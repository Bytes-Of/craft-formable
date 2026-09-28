<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\OptionsFormField;
use Craft;

/**
 * Radio button group.
 *
 * @internal
 */
final class Radio extends OptionsFormField
{
    public string $layout = 'vertical';

    public static function displayName(): string
    {
        return Craft::t('formable', 'Radio Buttons');
    }

    public static function icon(): string
    {
        return 'circle-dot';
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

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return array_merge(parent::defineSettingsSchema(), [
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
}
