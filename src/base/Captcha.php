<?php

declare(strict_types=1);

namespace bytesof\formable\base;

use Craft;
use craft\helpers\Json;
use Throwable;

/**
 * A captcha provider: the front-end widget plus the server-side check of the
 * token it produces.
 *
 * Deliberately self-contained rather than folded into the (still-unbuilt)
 * integrations framework - M9 can make these implement the integration
 * interface later. Each provider is constructed with its resolved keys, so the
 * class knows nothing about where credentials are stored and stays unit
 * testable without a booted plugin.
 *
 * Verification fails *closed*: an unverifiable token - a provider outage, a
 * missing response - counts as a failure. Paired with the default "flag, don't
 * reject" spam action, a genuine submitter caught by an outage lands in the
 * review queue rather than being turned away outright.
 *
 * @internal
 */
abstract class Captcha
{
    public function __construct(
        protected readonly string $siteKey,
        protected readonly string $secretKey,
    ) {
    }

    /**
     * The stable handle a form stores to say it uses this provider. Must match
     * one of {@see \bytesof\formable\models\FormSettings::captchaOptions()}.
     */
    abstract public static function handle(): string;

    abstract public static function displayName(): string;

    /**
     * The POST field the provider's widget submits its token under - the one
     * name the server has to know to find the token.
     */
    abstract public function responseParam(): string;

    abstract public function scriptUrl(): string;

    /**
     * The class the provider's own script scans the DOM for to render a widget.
     * Empty for an invisible provider that has no widget to render.
     */
    abstract public function widgetClass(): string;

    abstract protected function verifyUrl(): string;

    public function getSiteKey(): string
    {
        return $this->siteKey;
    }

    /**
     * Whether both keys are set. An unconfigured captcha is skipped rather than
     * enforced, so selecting a provider before pasting its keys never breaks a
     * live form.
     */
    public function isConfigured(): bool
    {
        return $this->siteKey !== '' && $this->secretKey !== '';
    }

    /**
     * Whether the provider scores a request in the background instead of
     * showing a challenge - reCAPTCHA v3. An invisible captcha needs JavaScript
     * to obtain a token at all, so a no-JS submitter can't satisfy one.
     */
    public function isInvisible(): bool
    {
        return false;
    }

    /**
     * Everything the front-end partial and JS need to place and wire the
     * widget. No secret ever appears here - this is handed to the browser.
     *
     * @return array<string, mixed>
     */
    public function getFrontEndData(): array
    {
        return [
            'type' => static::handle(),
            'siteKey' => $this->siteKey,
            'scriptUrl' => $this->scriptUrl(),
            'widgetClass' => $this->widgetClass(),
            'responseParam' => $this->responseParam(),
            'invisible' => $this->isInvisible(),
        ];
    }

    /**
     * Checks a token against the provider, returning whether it passed.
     *
     * An unconfigured provider passes everything - it isn't really in force. A
     * missing token, or any error reaching the provider, fails.
     */
    public function verify(?string $token, ?string $ip): bool
    {
        if (!$this->isConfigured()) {
            return true;
        }

        if ($token === null || $token === '') {
            return false;
        }

        try {
            $response = Craft::createGuzzleClient(['timeout' => 10])->post($this->verifyUrl(), [
                'form_params' => array_filter([
                    'secret' => $this->secretKey,
                    'response' => $token,
                    'remoteip' => $ip,
                ], static fn(?string $value): bool => $value !== null && $value !== ''),
            ]);

            $data = Json::decodeIfJson((string)$response->getBody());
        } catch (Throwable $e) {
            Craft::warning(
                sprintf('Formable could not reach the %s captcha service: %s', static::handle(), $e->getMessage()),
                __METHOD__,
            );

            return false;
        }

        return $this->verifyResponse(is_array($data) ? $data : []);
    }

    /**
     * Interprets the provider's decoded verify response. The base reads the
     * `success` flag every provider returns; a score-based provider overrides
     * to weigh the score too.
     *
     * @param array<string, mixed> $data
     */
    protected function verifyResponse(array $data): bool
    {
        return ($data['success'] ?? false) === true;
    }
}
