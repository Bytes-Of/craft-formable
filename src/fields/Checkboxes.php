<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\OptionsFormField;
use Craft;

/**
 * Checkbox group - zero or more selections.
 *
 * @internal
 */
final class Checkboxes extends OptionsFormField
{
    public string $layout = 'vertical';
    public ?int $min = null;
    public ?int $max = null;

    public static function displayName(): string
    {
        return Craft::t('formable', 'Checkboxes');
    }

    public static function icon(): string
    {
        return 'square-check';
    }

    public static function isMultiple(): bool
    {
        return true;
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

        $rules[] = [['layout'], 'in', 'range' => ['vertical', 'horizontal']];
        $rules[] = [['min', 'max'], 'integer', 'min' => 0];
        $rules[] = [['max'], 'compare', 'compareAttribute' => 'min', 'operator' => '>=', 'type' => 'number', 'skipOnEmpty' => true];

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return array_merge(parent::defineSettingsSchema(), [
            [
                'name' => 'min',
                'type' => 'number',
                'label' => Craft::t('formable', 'Min Selections'),
                'min' => 0,
            ],
            [
                'name' => 'max',
                'type' => 'number',
                'label' => Craft::t('formable', 'Max Selections'),
                'min' => 1,
            ],
            [
                'name' => 'layout',
                'type' => 'select',
                'label' => Craft::t('formable', 'Layout'),
                'default' => 'vertical',
                'group' => 'appearance',
                'options' => [
                    ['value' => 'vertical', 'label' => Craft::t('formable', 'Vertical')],
                    ['value' => 'horizontal', 'label' => Craft::t('formable', 'Horizontal')],
                ],
            ],
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function findValueErrors(mixed $value): array
    {
        $errors = [];
        $count = is_array($value) ? count($value) : 0;

        if ($this->min !== null && $count < $this->min) {
            $errors[] = Craft::t('formable', 'Select at least {min, plural, one{# option} other{# options}}.', [
                'min' => $this->min,
            ]);
        }

        if ($this->max !== null && $count > $this->max) {
            $errors[] = Craft::t('formable', 'Select at most {max, plural, one{# option} other{# options}}.', [
                'max' => $this->max,
            ]);
        }

        return $errors;
    }
}
