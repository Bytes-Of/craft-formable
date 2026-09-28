<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\OptionsFormField;
use Craft;

/**
 * Multi-select list box.
 *
 * @internal
 */
final class MultiSelect extends OptionsFormField
{
    public int $size = 5;

    public static function displayName(): string
    {
        return Craft::t('formable', 'Multi-select');
    }

    public static function icon(): string
    {
        return 'list-check';
    }

    public static function isMultiple(): bool
    {
        return true;
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['size'], 'integer', 'min' => 2, 'max' => 30];

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return array_merge(parent::defineSettingsSchema(), [
            [
                'name' => 'size',
                'type' => 'number',
                'label' => Craft::t('formable', 'Visible Rows'),
                'default' => 5,
                'min' => 2,
                'max' => 30,
                'group' => 'appearance',
            ],
        ]);
    }
}
