<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\FormField;
use Craft;

/**
 * Single-line text input.
 *
 * @internal
 */
class Text extends FormField
{
    public string $placeholder = '';
    public string $defaultValue = '';
    public ?int $limit = null;
    public string $inputType = 'text';

    public static function displayName(): string
    {
        return Craft::t('formable', 'Single-line Text');
    }

    public static function icon(): string
    {
        return 'text';
    }

    /**
     * @return array<int, string>
     */
    public static function translatableProperties(): array
    {
        return array_merge(parent::translatableProperties(), ['placeholder']);
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['placeholder', 'defaultValue'], 'string'];
        $rules[] = [['limit'], 'integer', 'min' => 1];

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return [
            [
                'name' => 'placeholder',
                'type' => 'text',
                'label' => Craft::t('formable', 'Placeholder'),
            ],
            [
                'name' => 'defaultValue',
                'type' => 'text',
                'label' => Craft::t('formable', 'Default Value'),
            ],
            [
                'name' => 'limit',
                'type' => 'number',
                'label' => Craft::t('formable', 'Character Limit'),
                'instructions' => Craft::t('formable', 'The maximum number of characters allowed. Leave empty for no limit.'),
                'min' => 1,
            ],
            [
                'name' => 'autocomplete',
                'type' => 'text',
                'label' => Craft::t('formable', 'Autocomplete'),
                'instructions' => Craft::t('formable', 'The browser autofill hint for this field, e.g. “organization”, “street-address” or “bday”. Leave empty for no hint.'),
                'group' => 'advanced',
            ],
        ];
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineValueValidationRules(): array
    {
        $rules = [['string']];

        if ($this->limit !== null) {
            $rules[] = ['string', 'max' => $this->limit];
        }

        return $rules;
    }

    protected static function supportsClientValidation(): bool
    {
        return true;
    }

    /**
     * @return array{maxLength?: ?int}
     */
    protected function defineValidationSpec(): array
    {
        return ['maxLength' => $this->limit];
    }

    public function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return null;
        }

        return $value === null ? null : trim((string)$value);
    }

    public function getDefaultValue(): mixed
    {
        return $this->defaultValue !== '' ? $this->defaultValue : null;
    }
}
