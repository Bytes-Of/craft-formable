<?php

declare(strict_types=1);

namespace bytesof\formable\integrations;

use bytesof\formable\base\Integration;
use bytesof\formable\elements\Submission;
use bytesof\formable\models\IntegrationResponse;
use Craft;

/**
 * Posts a submission summary to a Slack channel through an Incoming Webhook URL.
 *
 * Incoming Webhooks are the no-OAuth path to Slack: an admin creates one in
 * Slack (which fixes the destination channel) and pastes the URL here. The
 * message is a heading the author can template plus a field-by-field list of
 * the answers.
 *
 * @internal
 */
final class Slack extends Integration
{
    public static function type(): string
    {
        return 'slack';
    }

    public static function displayName(): string
    {
        return Craft::t('formable', 'Slack');
    }

    public static function category(): string
    {
        return self::CATEGORY_MISC;
    }

    public static function description(): string
    {
        return Craft::t('formable', 'Post a message to a Slack channel on each submission.');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getSettingsSchema(): array
    {
        return [
            [
                'name' => 'webhookUrl',
                'type' => 'text',
                'secret' => true,
                'label' => Craft::t('formable', 'Incoming Webhook URL'),
                'instructions' => Craft::t('formable', 'Create one at api.slack.com under “Incoming Webhooks”. The channel is fixed when you create it.'),
                'required' => true,
            ],
            [
                'name' => 'heading',
                'type' => 'text',
                'label' => Craft::t('formable', 'Message Heading'),
                'instructions' => Craft::t('formable', 'Shown in bold above the answers. Defaults to the form’s name.'),
            ],
        ];
    }

    public function isConfigured(): bool
    {
        return str_starts_with($this->env('webhookUrl'), 'https://hooks.slack.com/');
    }

    public function send(Submission $submission, array $mapped): IntegrationResponse
    {
        if (!$this->isConfigured()) {
            return IntegrationResponse::failed(Craft::t('formable', 'No valid Slack webhook URL is set.'));
        }

        $heading = $this->setting('heading');

        if ($heading === '') {
            $heading = Craft::t('formable', 'New submission: {form}', [
                'form' => $submission->getForm()->title ?? Craft::t('formable', 'Form'),
            ]);
        }

        return $this->request('POST', $this->env('webhookUrl'), [
            'text' => $this->message($heading, $submission),
        ]);
    }

    /**
     * A Slack-flavoured mrkdwn message: a bold heading, then one line per field.
     */
    private function message(string $heading, Submission $submission): string
    {
        $lines = ['*' . $heading . '*'];

        foreach ($submission->getFormFields() as $handle => $field) {
            if (!$field::hasValue()) {
                continue;
            }

            $value = trim($field->valueToString($submission->getValue($handle)));
            $label = $field->label !== '' ? $field->label : $handle;
            $lines[] = sprintf('*%s:* %s', $label, $value !== '' ? $value : '-');
        }

        return implode("\n", $lines);
    }
}
