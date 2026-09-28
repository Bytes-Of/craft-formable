<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\FormField;
use Craft;

/**
 * Hidden field, populated from a static value or a request-time source.
 *
 * The value source is resolved by the rendering layer; this class only
 * describes and stores the configuration.
 *
 * @internal
 */
final class Hidden extends FormField
{
    public const SOURCE_CUSTOM = 'custom';
    public const SOURCE_QUERY_PARAM = 'queryParam';
    public const SOURCE_COOKIE = 'cookie';
    public const SOURCE_CURRENT_URL = 'currentUrl';
    public const SOURCE_REFERRER = 'referrer';
    public const SOURCE_USER_ID = 'userId';
    public const SOURCE_USER_EMAIL = 'userEmail';

    public string $valueSource = self::SOURCE_CUSTOM;
    public string $defaultValue = '';

    /**
     * Query-string or cookie name, when the source needs one.
     */
    public string $sourceKey = '';

    public static function displayName(): string
    {
        return Craft::t('formable', 'Hidden');
    }

    public static function icon(): string
    {
        return 'eye-slash';
    }

    public static function group(): string
    {
        return self::GROUP_ADVANCED;
    }

    /**
     * Suppresses the wrapper label: there is no visible control to label.
     */
    public function rendersOwnLabel(): bool
    {
        return true;
    }

    /**
     * @return array<int, string>
     */
    public static function valueSources(): array
    {
        return [
            self::SOURCE_CUSTOM,
            self::SOURCE_QUERY_PARAM,
            self::SOURCE_COOKIE,
            self::SOURCE_CURRENT_URL,
            self::SOURCE_REFERRER,
            self::SOURCE_USER_ID,
            self::SOURCE_USER_EMAIL,
        ];
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['valueSource'], 'in', 'range' => self::valueSources()];
        $rules[] = [['defaultValue', 'sourceKey'], 'string'];
        $rules[] = [
            ['sourceKey'],
            'required',
            'when' => fn(): bool => in_array($this->valueSource, [self::SOURCE_QUERY_PARAM, self::SOURCE_COOKIE], true),
        ];

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return [
            [
                'name' => 'valueSource',
                'type' => 'select',
                'label' => Craft::t('formable', 'Value Source'),
                'default' => self::SOURCE_CUSTOM,
                'options' => [
                    ['value' => self::SOURCE_CUSTOM, 'label' => Craft::t('formable', 'Custom value')],
                    ['value' => self::SOURCE_QUERY_PARAM, 'label' => Craft::t('formable', 'Query string parameter')],
                    ['value' => self::SOURCE_COOKIE, 'label' => Craft::t('formable', 'Cookie')],
                    ['value' => self::SOURCE_CURRENT_URL, 'label' => Craft::t('formable', 'Current URL')],
                    ['value' => self::SOURCE_REFERRER, 'label' => Craft::t('formable', 'HTTP referrer')],
                    ['value' => self::SOURCE_USER_ID, 'label' => Craft::t('formable', 'Logged-in user ID')],
                    ['value' => self::SOURCE_USER_EMAIL, 'label' => Craft::t('formable', 'Logged-in user email')],
                ],
            ],
            [
                'name' => 'sourceKey',
                'type' => 'text',
                'label' => Craft::t('formable', 'Parameter Name'),
                'when' => ['valueSource' => [self::SOURCE_QUERY_PARAM, self::SOURCE_COOKIE]],
            ],
            [
                'name' => 'defaultValue',
                'type' => 'text',
                'label' => Craft::t('formable', 'Default Value'),
                'instructions' => Craft::t('formable', 'Used when the source yields nothing.'),
            ],
        ];
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineValueValidationRules(): array
    {
        return [['string']];
    }

    public function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return null;
        }

        return $value === null ? null : (string)$value;
    }

    public function getDefaultValue(): mixed
    {
        return $this->defaultValue !== '' ? $this->defaultValue : null;
    }

    /**
     * A hidden field has no visible label or instructions for a submitter to
     * read, so there is nothing here for a site override to change.
     *
     * @return array<int, string>
     */
    public static function translatableProperties(): array
    {
        return [];
    }
}
