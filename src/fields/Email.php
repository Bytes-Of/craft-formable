<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use Craft;

/**
 * Email address input.
 *
 * @internal
 */
final class Email extends Text
{
    public string $inputType = 'email';
    public string $autocomplete = 'email';

    /**
     * Whether the submitter must enter the address twice.
     */
    public bool $requireConfirmation = false;

    public static function displayName(): string
    {
        return Craft::t('formable', 'Email Address');
    }

    public static function icon(): string
    {
        return 'envelope';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return array_merge(parent::defineSettingsSchema(), [
            [
                'name' => 'requireConfirmation',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Require Confirmation'),
                'instructions' => Craft::t('formable', 'Show a second field the submitter must match.'),
                'default' => false,
            ],
        ]);
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineValueValidationRules(): array
    {
        return array_merge(parent::defineValueValidationRules(), [
            ['email'],
        ]);
    }

    /**
     * @return array{type: string, maxLength?: ?int}
     */
    protected function defineValidationSpec(): array
    {
        return array_merge(parent::defineValidationSpec(), ['type' => 'email']);
    }

    public function normalizeValue(mixed $value): mixed
    {
        $value = parent::normalizeValue($value);

        return is_string($value) ? mb_strtolower($value) : $value;
    }

    /**
     * @inheritdoc
     */
    public function getSubValueNames(): array
    {
        return $this->requireConfirmation ? ['confirm' => $this->subValueName('confirm')] : [];
    }

    /**
     * @inheritdoc
     */
    protected function findSubValueErrors(mixed $value, array $subValues): array
    {
        // Absent rather than empty means the confirmation box wasn't on the
        // page that was posted - a multi-page form validating every page at
        // the end, say - so there is nothing to compare against.
        if (!$this->requireConfirmation || !array_key_exists('confirm', $subValues)) {
            return [];
        }

        $confirm = $this->normalizeValue($subValues['confirm']);

        // Nothing typed in either box is the "required" check's business, not
        // this one's.
        if (($value ?? '') === '' && ($confirm ?? '') === '') {
            return [];
        }

        if ($confirm === $value) {
            return [];
        }

        return [
            Craft::t('formable', '{label} doesn’t match its confirmation.', [
                'label' => $this->label !== '' ? $this->label : $this->handle,
            ]),
        ];
    }
}
