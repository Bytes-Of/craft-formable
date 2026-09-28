<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\FormField;
use Craft;

/**
 * A single rating from 1 to a configurable scale.
 *
 * @internal
 */
final class Rating extends FormField
{
    public int $max = 5;

    public static function displayName(): string
    {
        return Craft::t('formable', 'Rating');
    }

    public static function icon(): string
    {
        return 'star';
    }

    public static function group(): string
    {
        return self::GROUP_CHOICE;
    }

    public function usesFieldset(): bool
    {
        return true;
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['max'], 'integer', 'min' => 2, 'max' => 10];

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return [
            [
                'name' => 'max',
                'type' => 'number',
                'label' => Craft::t('formable', 'Scale'),
                'instructions' => Craft::t('formable', 'The highest rating the submitter can give, starting from 1.'),
                'default' => 5,
                'min' => 2,
                'max' => 10,
            ],
        ];
    }

    /**
     * The selectable ratings, 1 through {@see $max}.
     *
     * @return array<int, int>
     */
    public function getScale(): array
    {
        return range(1, $this->max);
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineValueValidationRules(): array
    {
        return [
            ['integer', 'min' => 1, 'max' => $this->max],
        ];
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

        return (int)$value;
    }

    public function getDefaultValue(): mixed
    {
        return null;
    }
}
