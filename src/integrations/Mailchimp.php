<?php

declare(strict_types=1);

namespace bytesof\formable\integrations;

use bytesof\formable\base\EmailMarketing;
use bytesof\formable\elements\Submission;
use bytesof\formable\models\IntegrationResponse;
use Craft;

/**
 * Subscribes the submitter to a Mailchimp audience.
 *
 * Authentication is a classic API key (`…-us21`), whose `-` suffix names the
 * data centre the account lives in - so the endpoint is derived from the key
 * and never asked for separately. The member is written with a PUT upsert keyed
 * on the lower-cased email's MD5, which both creates a new subscriber and
 * updates an existing one, so a repeat submission is never a duplicate-error.
 *
 * @internal
 */
final class Mailchimp extends EmailMarketing
{
    public static function type(): string
    {
        return 'mailchimp';
    }

    public static function displayName(): string
    {
        return Craft::t('formable', 'Mailchimp');
    }

    public static function description(): string
    {
        return Craft::t('formable', 'Add submitters to a Mailchimp audience.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSettingsSchema(): array
    {
        return [
            [
                'name' => 'apiKey',
                'type' => 'text',
                'secret' => true,
                'label' => Craft::t('formable', 'API Key'),
                'instructions' => Craft::t('formable', 'From Mailchimp under Account → Extras → API keys.'),
                'required' => true,
            ],
            [
                'name' => 'audienceId',
                'type' => 'text',
                'label' => Craft::t('formable', 'Audience ID'),
                'instructions' => Craft::t('formable', 'The list/audience to subscribe to, from Audience → Settings.'),
                'required' => true,
            ],
            [
                'name' => 'doubleOptIn',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Double Opt-In'),
                'instructions' => Craft::t('formable', 'Send a confirmation email before subscribing. Recommended for GDPR.'),
            ],
        ];
    }

    /**
     * @return array<int, array{handle: string, name: string, required?: bool}>
     */
    public function getMappableFields(): array
    {
        return [
            ['handle' => 'EMAIL', 'name' => Craft::t('formable', 'Email Address'), 'required' => true],
            ['handle' => 'FNAME', 'name' => Craft::t('formable', 'First Name')],
            ['handle' => 'LNAME', 'name' => Craft::t('formable', 'Last Name')],
        ];
    }

    public function isConfigured(): bool
    {
        return $this->dataCenter() !== null && $this->setting('audienceId') !== '';
    }

    public function send(Submission $submission, array $mapped): IntegrationResponse
    {
        $email = $this->mappedEmail($mapped);

        if ($email === null) {
            return IntegrationResponse::failed(Craft::t('formable', 'The mapped email field was empty or invalid.'));
        }

        $dc = $this->dataCenter();

        if ($dc === null) {
            return IntegrationResponse::failed(Craft::t('formable', 'The Mailchimp API key looks malformed.'));
        }

        $mergeFields = [];

        foreach (['FNAME', 'LNAME'] as $tag) {
            $value = $mapped[$tag] ?? null;

            if (is_scalar($value) && trim((string)$value) !== '') {
                $mergeFields[$tag] = trim((string)$value);
            }
        }

        $url = sprintf(
            'https://%s.api.mailchimp.com/3.0/lists/%s/members/%s',
            $dc,
            rawurlencode($this->setting('audienceId')),
            md5(strtolower($email)),
        );

        $body = [
            'email_address' => $email,
            // `subscribed` on a new member sends no confirmation; `pending`
            // triggers the double opt-in email. An upsert never *downgrades* an
            // already-subscribed member, so this is safe to send every time.
            'status_if_new' => $this->boolSetting('doubleOptIn') ? 'pending' : 'subscribed',
        ];

        if ($mergeFields !== []) {
            $body['merge_fields'] = $mergeFields;
        }

        return $this->request('PUT', $url, $body, [
            'Authorization' => 'Basic ' . base64_encode('formable:' . $this->env('apiKey')),
        ]);
    }

    public function checkConnection(): IntegrationResponse
    {
        $dc = $this->dataCenter();

        if ($dc === null || $this->setting('audienceId') === '') {
            return IntegrationResponse::failed(Craft::t('formable', 'Enter an API key and audience ID first.'));
        }

        // A GET on the audience proves both the key and the audience ID; sent as
        // a bodyless request through the shared classifier.
        return $this->request('GET', sprintf(
            'https://%s.api.mailchimp.com/3.0/lists/%s',
            $dc,
            rawurlencode($this->setting('audienceId')),
        ), [], [
            'Authorization' => 'Basic ' . base64_encode('formable:' . $this->env('apiKey')),
        ]);
    }

    /**
     * The data-centre suffix baked into the API key (`…-us21` → `us21`), or null
     * when the key is empty or has no suffix.
     */
    private function dataCenter(): ?string
    {
        $key = $this->env('apiKey');
        $dash = strrpos($key, '-');

        if ($dash === false) {
            return null;
        }

        $dc = substr($key, $dash + 1);

        return $dc !== '' ? $dc : null;
    }
}
