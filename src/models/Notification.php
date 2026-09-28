<?php

declare(strict_types=1);

namespace bytesof\formable\models;

use Craft;
use craft\base\Model;

/**
 * A per-form email notification.
 *
 * Every address field and both the subject and body are Twig-renderable against
 * the submission - the recipient list can therefore be an authored constant
 * (`team@example.com`) or come from an email field on the form itself
 * (`{{ email }}`), which is what makes one model serve both the admin
 * notification and the submitter autoresponder.
 *
 * `conditions` is an opaque {@see ConditionSet} config here - evaluated by the
 * shared conditions engine so “only email sales when budget is over 10k” is
 * authored with the same UI as a conditionally-shown field.
 *
 * @internal
 */
final class Notification extends Model
{
    public ?int $id = null;
    public ?int $formId = null;
    public string $name = '';
    public bool $enabled = true;

    /**
     * Primary recipients. Comma-, semicolon- or newline-separated, each entry
     * Twig-rendered and then validated as an address before it's used.
     */
    public string $recipients = '';

    public string $cc = '';
    public string $bcc = '';
    public string $replyTo = '';
    public string $fromName = '';
    public string $fromEmail = '';
    public string $subject = '';
    public string $body = '';

    /**
     * Whether the submission's uploaded files ride along as attachments.
     */
    public bool $attachFiles = false;

    /**
     * Conditional-sending rule set, in {@see ConditionSet} config shape. Opaque
     * here - the model only round-trips it.
     *
     * @var array<string, mixed>
     */
    public array $conditions = [];

    public int $sortOrder = 0;
    public ?string $uid = null;

    /**
     * Builds a notification from a builder payload, keeping only keys the model
     * declares so a downgrade can't smuggle a removed one back in.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $notification = new self();
        $notification->id = isset($config['id']) && is_numeric($config['id']) ? (int)$config['id'] : null;
        $notification->name = self::str($config['name'] ?? '');
        $notification->enabled = (bool)($config['enabled'] ?? true);
        $notification->recipients = self::str($config['recipients'] ?? '');
        $notification->cc = self::str($config['cc'] ?? '');
        $notification->bcc = self::str($config['bcc'] ?? '');
        $notification->replyTo = self::str($config['replyTo'] ?? '');
        $notification->fromName = self::str($config['fromName'] ?? '');
        $notification->fromEmail = self::str($config['fromEmail'] ?? '');
        $notification->subject = self::str($config['subject'] ?? '');
        $notification->body = self::str($config['body'] ?? '');
        $notification->attachFiles = (bool)($config['attachFiles'] ?? false);
        $notification->conditions = is_array($config['conditions'] ?? null) ? $config['conditions'] : [];
        $notification->sortOrder = isset($config['sortOrder']) && is_numeric($config['sortOrder'])
            ? (int)$config['sortOrder']
            : 0;

        return $notification;
    }

    /**
     * The notification's conditional-sending rule set.
     */
    public function getConditionSet(): ConditionSet
    {
        return ConditionSet::fromArray($this->conditions);
    }

    /**
     * The stored shape, for the builder and for persistence - conditions
     * emitted in their canonical `{ enabled, action, match, rules }` form so
     * the JS editor never sees a bare `[]`.
     *
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'enabled' => $this->enabled,
            'recipients' => $this->recipients,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'replyTo' => $this->replyTo,
            'fromName' => $this->fromName,
            'fromEmail' => $this->fromEmail,
            'subject' => $this->subject,
            'body' => $this->body,
            'attachFiles' => $this->attachFiles,
            'conditions' => $this->getConditionSet()->toConfig(),
            'sortOrder' => $this->sortOrder,
        ];
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['name', 'recipients'], 'required'];
        $rules[] = [
            [
                'name', 'recipients', 'cc', 'bcc', 'replyTo',
                'fromName', 'fromEmail', 'subject', 'body',
            ],
            'string',
        ];
        $rules[] = [['name'], 'string', 'max' => 255];
        // The address fields aren't validated as emails here: they may hold
        // Twig (`{{ email }}`) that only resolves against a submission, so
        // address validation happens at send time on the rendered result.
        $rules[] = [['enabled', 'attachFiles'], 'boolean'];
        $rules[] = [['sortOrder', 'formId'], 'integer'];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributeLabels(): array
    {
        return [
            'name' => Craft::t('formable', 'Name'),
            'recipients' => Craft::t('formable', 'Recipients'),
            'cc' => Craft::t('formable', 'CC'),
            'bcc' => Craft::t('formable', 'BCC'),
            'replyTo' => Craft::t('formable', 'Reply-To'),
            'fromName' => Craft::t('formable', 'From Name'),
            'fromEmail' => Craft::t('formable', 'From Email'),
            'subject' => Craft::t('formable', 'Subject'),
            'body' => Craft::t('formable', 'Body'),
            'attachFiles' => Craft::t('formable', 'Attach uploaded files'),
        ];
    }

    private static function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
