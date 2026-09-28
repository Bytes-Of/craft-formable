<?php

declare(strict_types=1);

namespace bytesof\formable\base;

use bytesof\formable\elements\Submission;
use bytesof\formable\helpers\OutboundUrl;
use bytesof\formable\models\IntegrationResponse;
use bytesof\formable\Plugin;
use Craft;
use craft\helpers\App;
use craft\helpers\Json;
use GuzzleHttp\Exception\BadResponseException;
use Throwable;

/**
 * A third-party integration: somewhere a submission is forwarded after it is
 * accepted - a CRM, an email list, Slack, a raw webhook.
 *
 * The design mirrors {@see Captcha}: a provider is a small, self-contained
 * class constructed with its resolved connection settings, so it knows nothing
 * about where those came from and stays unit-testable without a booted plugin.
 * One provider class is a *type* (Mailchimp, the generic webhook); the CP lets
 * an admin create any number of named *instances* of a type, each with its own
 * credentials, stored in the integrations table.
 *
 * The five categories the plugin recognises are {@see Captcha} (handled on its
 * own, before this framework existed) plus the four here - email marketing,
 * CRM, webhook and miscellaneous. Rather than an empty subclass per category,
 * the two that carry shared behaviour are abstract bases ({@see EmailMarketing},
 * {@see Crm}); webhook and miscellaneous providers extend this root directly and
 * declare their category. New categories third parties add via the registry
 * event are grouped under {@see CATEGORY_MISC} in the UI.
 *
 * Delivery is attempted off the request path in a queue job and every attempt
 * lands one row in the integrations log, so a slow or flaky endpoint never
 * holds up the submitter and a failure is always diagnosable after the fact.
 *
 * @api
 */
abstract class Integration
{
    public const CATEGORY_EMAIL = 'emailMarketing';
    public const CATEGORY_CRM = 'crm';
    public const CATEGORY_WEBHOOK = 'webhook';
    public const CATEGORY_MISC = 'miscellaneous';

    /**
     * @param array<string, mixed> $settings The instance's stored connection
     *   settings. Secrets may be `$ENV_VAR` references; {@see env()} resolves
     *   them, {@see setting()} returns them verbatim.
     */
    public function __construct(
        protected readonly array $settings = [],
    ) {
    }

    /**
     * The stable handle identifying this provider type - stored in the
     * integration row's `type` column and used to rebuild the class. Must be
     * unique across registered providers.
     */
    abstract public static function type(): string;

    abstract public static function displayName(): string;

    /**
     * One of the `CATEGORY_*` constants, for grouping in the CP.
     */
    abstract public static function category(): string;

    /**
     * A one-line description shown on the integration picker.
     */
    public static function description(): string
    {
        return '';
    }

    /**
     * The connection settings this provider needs, in the same declarative
     * shape the field settings drawer uses - so the CP edit screen renders
     * itself from this without a bespoke template per provider.
     *
     * A control may carry `secret: true`, which renders it as an env-suggesting
     * field and marks its value for `$ENV_VAR` resolution.
     *
     * @return array<int, array<string, mixed>>
     */
    abstract public function getSettingsSchema(): array;

    /**
     * The target fields a form maps its own fields onto - a CRM's contact
     * properties, an email list's merge tags. Empty for a provider that takes
     * the whole submission as-is (a webhook, a Slack summary).
     *
     * @return array<int, array{handle: string, name: string, required?: bool}>
     */
    public function getMappableFields(): array
    {
        return [];
    }

    /**
     * Whether the instance has enough to attempt a call. An unconfigured
     * integration is skipped, never enforced - so creating one and saving it
     * before pasting its credentials can't break a live form.
     */
    abstract public function isConfigured(): bool;

    /**
     * Forwards one accepted submission.
     *
     * `$mapped` is the resolved map of {@see getMappableFields()} target handle
     * to the submission's value for the form field the author mapped onto it -
     * empty for a provider that declares no mappable fields and reads the
     * submission directly.
     *
     * @param array<string, mixed> $mapped
     */
    abstract public function send(Submission $submission, array $mapped): IntegrationResponse;

    /**
     * A cheap round-trip that proves the credentials work, for the CP's "test
     * connection" button. Defaults to reporting the instance simply isn't
     * configured; providers that can verify override this.
     */
    public function checkConnection(): IntegrationResponse
    {
        if (!$this->isConfigured()) {
            return IntegrationResponse::failed(
                Craft::t('formable', 'This integration isn’t configured yet.'),
            );
        }

        return IntegrationResponse::success();
    }

    /**
     * A stored setting verbatim (an `$ENV_VAR` reference is returned unresolved).
     */
    protected function setting(string $key, string $default = ''): string
    {
        $value = $this->settings[$key] ?? $default;

        return is_scalar($value) ? trim((string)$value) : $default;
    }

    protected function boolSetting(string $key, bool $default = false): bool
    {
        return (bool)($this->settings[$key] ?? $default);
    }

    /**
     * A stored setting with any `$ENV_VAR` reference resolved - for secrets,
     * which are stored as references the same way every other Craft credential
     * is.
     */
    protected function env(string $key): string
    {
        return trim((string)App::parseEnv($this->setting($key)));
    }

    /**
     * Sends a JSON request and classifies the answer into an {@see IntegrationResponse}.
     *
     * The classification is the one every REST endpoint the launch set talks to
     * follows: a 2xx succeeds; a 429 or 5xx is transient and worth retrying; any
     * other 4xx is a permanent failure the same payload would only trigger
     * again. The request and decoded response are carried through for the log.
     *
     * The URL is checked first: one that reaches a private or reserved address
     * is refused, and the connection is pinned to the addresses that passed so a
     * DNS answer can't change between the check and the call. Redirects are not
     * followed, since each hop would need the same check and a POST rarely
     * survives one intact; a 3xx is reported so the author can fix the URL.
     *
     * @param array<string, mixed> $body
     * @param array<string, string> $headers
     */
    protected function request(string $method, string $url, array $body, array $headers = []): IntegrationResponse
    {
        $allowed = $this->allowedTargets();
        $host = strtolower(trim((string)parse_url($url, PHP_URL_HOST), '[]'));

        // Resolved once and reused for the pin below: a second lookup would
        // reopen the window the pin exists to close.
        $addresses = $host === '' ? [] : OutboundUrl::addressesFor($host);
        $violation = OutboundUrl::violation($url, $allowed, static fn(): array => $addresses);

        if ($violation !== null) {
            return IntegrationResponse::failed($this->blockedMessage($violation), $body);
        }

        $options = [
            'headers' => $headers,
            'json' => $body,
            'allow_redirects' => false,
        ];

        $pin = $this->connectionPin($url, $host, $addresses, $allowed);

        if ($pin !== null) {
            $options['curl'] = [CURLOPT_RESOLVE => [$pin]];
        }

        try {
            $response = Craft::createGuzzleClient(['timeout' => 15])->request($method, $url, $options);
        } catch (BadResponseException $e) {
            // A response with a non-2xx status - Guzzle throws by default.
            $status = $e->getResponse()->getStatusCode();
            $decoded = $this->decode((string)$e->getResponse()->getBody());
            $message = Craft::t('formable', 'The service responded with {status}.', ['status' => $status]);

            return $status === 429 || $status >= 500
                ? IntegrationResponse::retryable($message, $body, $decoded)
                : IntegrationResponse::failed($message, $body, $decoded);
        } catch (Throwable $e) {
            // A connection-level problem (DNS, timeout, TLS) - always worth a retry.
            return IntegrationResponse::retryable($e->getMessage(), $body);
        }

        if ($response->getStatusCode() >= 300) {
            return IntegrationResponse::failed(
                Craft::t('formable', 'The service redirected the request. Formable does not follow redirects - update the URL to its final address.'),
                $body,
            );
        }

        return IntegrationResponse::success($body, $this->decode((string)$response->getBody()));
    }

    /**
     * The hosts, addresses and ranges the site lets integrations reach even
     * though they are private. Empty when the plugin isn't booted (a unit test),
     * which is the strict answer.
     *
     * @return array<int, string>
     */
    protected function allowedTargets(): array
    {
        $plugin = Plugin::getInstance();

        return OutboundUrl::parseAllowlist($plugin === null ? '' : $plugin->getSettings()->integrationAllowedHosts);
    }

    /**
     * The message for a URL the guard refused, saying where to allow it.
     */
    protected function blockedMessage(string $violation): string
    {
        return Craft::t('formable', '{reason} Formable only sends requests to public addresses. To allow this one, add it to “Integration Allowed Hosts” in the plugin settings.', [
            'reason' => $violation,
        ]);
    }

    /**
     * A cURL `--resolve` entry binding the URL's host to the addresses the guard
     * validated, or null when there's nothing to pin (an IP literal, or a host
     * the site allowlisted by name).
     *
     * @param array<int, string> $addresses
     * @param array<int, string> $allowed
     */
    private function connectionPin(string $url, string $host, array $addresses, array $allowed): ?string
    {
        if ($addresses === [] || in_array($host, $allowed, true) || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return null;
        }

        $scheme = strtolower((string)parse_url($url, PHP_URL_SCHEME));
        $port = parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);

        return sprintf('%s:%d:%s', $host, $port, implode(',', $addresses));
    }

    /**
     * Decodes a JSON body to an array, or an empty array for a non-JSON one
     * (a Slack webhook answers with the bare word `ok`).
     *
     * @return array<string, mixed>
     */
    private function decode(string $body): array
    {
        $decoded = Json::decodeIfJson($body);

        return is_array($decoded) ? $decoded : [];
    }
}
