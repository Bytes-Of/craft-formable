<?php

declare(strict_types=1);

namespace bytesof\formable\integrations;

use bytesof\formable\base\Integration;
use bytesof\formable\elements\Submission;
use bytesof\formable\helpers\OutboundUrl;
use bytesof\formable\models\IntegrationResponse;
use Craft;

/**
 * The generic webhook: POSTs (or PUTs) the whole submission as JSON to a URL of
 * the author's choosing - the catch-all that lets a form reach any service the
 * launch set doesn't cover directly.
 *
 * It declares no mappable fields: the payload is the entire submission, so
 * there's nothing to map. An optional signing header lets the receiver verify
 * the request came from this site.
 *
 * @internal
 */
final class Webhook extends Integration
{
    public static function type(): string
    {
        return 'webhook';
    }

    public static function displayName(): string
    {
        return Craft::t('formable', 'Webhook');
    }

    public static function category(): string
    {
        return self::CATEGORY_WEBHOOK;
    }

    public static function description(): string
    {
        return Craft::t('formable', 'Send every submission as JSON to any URL.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSettingsSchema(): array
    {
        return [
            [
                'name' => 'url',
                'type' => 'text',
                'label' => Craft::t('formable', 'Webhook URL'),
                'instructions' => Craft::t('formable', 'The endpoint each submission is sent to.'),
                'required' => true,
            ],
            [
                'name' => 'method',
                'type' => 'select',
                'label' => Craft::t('formable', 'HTTP Method'),
                'options' => [
                    ['value' => 'POST', 'label' => 'POST'],
                    ['value' => 'PUT', 'label' => 'PUT'],
                ],
            ],
            [
                'name' => 'secret',
                'type' => 'text',
                'secret' => true,
                'label' => Craft::t('formable', 'Signing Secret'),
                'instructions' => Craft::t('formable', 'Optional. Sent as the {header} header so the receiver can verify the request.', [
                    'header' => 'X-Formable-Signature',
                ]),
            ],
        ];
    }

    public function isConfigured(): bool
    {
        return filter_var($this->setting('url'), FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Nothing is sent to a webhook to test it - the receiver would get a fake
     * submission - but the URL can still be checked against the outbound guard,
     * so a blocked address is reported here rather than only in a failed
     * delivery's log.
     */
    public function checkConnection(): IntegrationResponse
    {
        $response = parent::checkConnection();
        $violation = $response->success
            ? OutboundUrl::violation($this->setting('url'), $this->allowedTargets())
            : null;

        return $violation === null ? $response : IntegrationResponse::failed($this->blockedMessage($violation));
    }

    public function send(Submission $submission, array $mapped): IntegrationResponse
    {
        if (!$this->isConfigured()) {
            return IntegrationResponse::failed(Craft::t('formable', 'No webhook URL is set.'));
        }

        $form = $submission->getForm();

        $body = [
            'form' => [
                'id' => $form?->id,
                'handle' => $form?->handle,
                'title' => $form?->title,
            ],
            'submission' => [
                'id' => $submission->id,
                'dateCreated' => $submission->dateCreated?->format(DATE_ATOM),
            ],
            'data' => $this->submissionData($submission),
        ];

        $headers = ['Accept' => 'application/json'];
        $secret = $this->env('secret');

        if ($secret !== '') {
            // A deterministic HMAC over the exact JSON we send, so the receiver
            // can recompute it from the raw body and reject anything unsigned.
            $headers['X-Formable-Signature'] = hash_hmac('sha256', (string)json_encode($body), $secret);
        }

        return $this->request($this->method(), $this->setting('url'), $body, $headers);
    }

    /**
     * The submission's values keyed by field handle, each rendered to a plain
     * string so the payload is stable regardless of a field's internal shape.
     *
     * @return array<string, string>
     */
    private function submissionData(Submission $submission): array
    {
        $data = [];

        foreach ($submission->getFormFields() as $handle => $field) {
            if ($field::hasValue()) {
                $data[$handle] = $field->valueToString($submission->getValue($handle));
            }
        }

        return $data;
    }

    private function method(): string
    {
        $method = strtoupper($this->setting('method', 'POST'));

        return in_array($method, ['POST', 'PUT'], true) ? $method : 'POST';
    }
}
