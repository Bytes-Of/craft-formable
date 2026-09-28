<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\OptionsFormField;
use Craft;

/**
 * Single-select dropdown.
 *
 * @internal
 */
final class Dropdown extends OptionsFormField
{
    /**
     * Text for the empty leading option. Empty means no placeholder option.
     */
    public string $placeholder = '';

    public static function displayName(): string
    {
        return Craft::t('formable', 'Dropdown');
    }

    public static function icon(): string
    {
        return 'chevron-down';
    }

    /**
     * @return array<int, string>
     */
    public static function translatableProperties(): array
    {
        return array_merge(parent::translatableProperties(), ['placeholder']);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return array_merge([
            [
                'name' => 'placeholder',
                'type' => 'text',
                'label' => Craft::t('formable', 'Placeholder Option'),
                'instructions' => Craft::t('formable', 'Text for the empty leading option. Leave blank to omit it.'),
            ],
        ], parent::defineSettingsSchema());
    }
}
