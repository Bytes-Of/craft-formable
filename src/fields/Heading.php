<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\CosmeticFormField;
use Craft;

/**
 * Section heading rendered between fields.
 *
 * @internal
 */
final class Heading extends CosmeticFormField
{
    /**
     * Heading level. Constrained to h2–h6 so a form can never introduce a
     * second h1 or skip the document outline.
     */
    public string $level = 'h3';

    public static function displayName(): string
    {
        return Craft::t('formable', 'Heading');
    }

    public static function icon(): string
    {
        return 'heading';
    }

    /**
     * @return array<int, string>
     */
    public static function levels(): array
    {
        return ['h2', 'h3', 'h4', 'h5', 'h6'];
    }

    /**
     * A heading has no instructions of its own to translate - only the
     * heading text itself.
     *
     * @return array<int, string>
     */
    public static function translatableProperties(): array
    {
        return ['label'];
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['level'], 'in', 'range' => self::levels()];

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return [
            [
                'name' => 'level',
                'type' => 'select',
                'label' => Craft::t('formable', 'Heading Level'),
                'default' => 'h3',
                'options' => array_map(
                    static fn(string $level): array => ['value' => $level, 'label' => strtoupper($level)],
                    self::levels(),
                ),
            ],
        ];
    }
}
