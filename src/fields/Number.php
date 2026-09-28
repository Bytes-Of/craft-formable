<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\FormField;
use Craft;

/**
 * Numeric input.
 *
 * @internal
 */
final class Number extends FormField
{
    public string $placeholder = '';
    public ?float $min = null;
    public ?float $max = null;
    public int $decimals = 0;
    public ?float $defaultValue = null;

    public static function displayName(): string
    {
        return Craft::t('formable', 'Number');
    }

    public static function icon(): string
    {
        return 'hashtag';
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

        $rules[] = [['min', 'max', 'defaultValue'], 'number'];
        $rules[] = [['decimals'], 'integer', 'min' => 0, 'max' => 8];
        $rules[] = [['max'], 'compare', 'compareAttribute' => 'min', 'operator' => '>=', 'type' => 'number', 'skipOnEmpty' => true];

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
                'name' => 'min',
                'type' => 'number',
                'label' => Craft::t('formable', 'Min Value'),
            ],
            [
                'name' => 'max',
                'type' => 'number',
                'label' => Craft::t('formable', 'Max Value'),
            ],
            [
                'name' => 'decimals',
                'type' => 'number',
                'label' => Craft::t('formable', 'Decimal Places'),
                'default' => 0,
                'min' => 0,
                'max' => 8,
            ],
            [
                'name' => 'defaultValue',
                'type' => 'number',
                'label' => Craft::t('formable', 'Default Value'),
            ],
        ];
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineValueValidationRules(): array
    {
        $rule = ['number', 'integerOnly' => $this->decimals === 0];

        if ($this->min !== null) {
            $rule['min'] = $this->min;
        }

        if ($this->max !== null) {
            $rule['max'] = $this->max;
        }

        return [$rule];
    }

    protected static function supportsClientValidation(): bool
    {
        return true;
    }

    /**
     * @return array{type: string, min: ?float, max: ?float}
     */
    protected function defineValidationSpec(): array
    {
        return ['type' => 'number', 'min' => $this->min, 'max' => $this->max];
    }

    /**
     * The `step` attribute matching the configured precision.
     */
    public function getStep(): string
    {
        if ($this->decimals === 0) {
            return '1';
        }

        return '0.' . str_repeat('0', $this->decimals - 1) . '1';
    }

    public function normalizeValue(mixed $value): mixed
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        if (!is_numeric($value)) {
            // Preserve the bad input so the validator can report it rather
            // than silently coercing it to 0.
            return is_scalar($value) ? (string)$value : null;
        }

        // Adding zero yields an int or a float naturally, so "1.5" stays a
        // float and the integerOnly rule can reject it instead of it being
        // silently truncated to 1.
        $number = $value + 0;

        return $this->decimals === 0 ? $number : round((float)$number, $this->decimals);
    }

    public function getDefaultValue(): mixed
    {
        if ($this->defaultValue === null) {
            return null;
        }

        return $this->decimals === 0 ? (int)$this->defaultValue : $this->defaultValue;
    }
}
