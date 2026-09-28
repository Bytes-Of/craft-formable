<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use Craft;

/**
 * Multi-line text input.
 *
 * @internal
 */
final class Textarea extends Text
{
    public int $rows = 4;

    public static function displayName(): string
    {
        return Craft::t('formable', 'Multi-line Text');
    }

    public static function icon(): string
    {
        return 'align-left';
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['rows'], 'integer', 'min' => 1, 'max' => 50];

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return array_merge(parent::defineSettingsSchema(), [
            [
                'name' => 'rows',
                'type' => 'number',
                'label' => Craft::t('formable', 'Rows'),
                'default' => 4,
                'min' => 1,
                'max' => 50,
            ],
        ]);
    }
}
