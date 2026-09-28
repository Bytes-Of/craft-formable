<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\CosmeticFormField;
use Craft;

/**
 * Arbitrary HTML block - intro copy, legal text, an embedded image.
 *
 * Authoring needs only `formable:saveForms`, not an admin account, so the
 * content is purified on render rather than trusted outright - see
 * internal/decisions/0099.
 *
 * @internal
 */
final class Html extends CosmeticFormField
{
    public string $content = '';

    public static function displayName(): string
    {
        return Craft::t('formable', 'HTML Block');
    }

    public static function icon(): string
    {
        return 'code';
    }

    /**
     * An HTML block has no label or instructions rendered anywhere - only its
     * content.
     *
     * @return array<int, string>
     */
    public static function translatableProperties(): array
    {
        return ['content'];
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['content'], 'string'];

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return [
            [
                'name' => 'content',
                'type' => 'richText',
                'label' => Craft::t('formable', 'Content'),
            ],
        ];
    }
}
