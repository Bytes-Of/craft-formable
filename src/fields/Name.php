<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\CompositeFormField;
use Craft;

/**
 * Person's name, either as one input or split into parts.
 *
 * @internal
 */
final class Name extends CompositeFormField
{
    public static function displayName(): string
    {
        return Craft::t('formable', 'Name');
    }

    public static function icon(): string
    {
        return 'user';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function defineSubFields(): array
    {
        return [
            'prefix' => [
                'label' => Craft::t('formable', 'Prefix'),
                'enabled' => false,
                'autocomplete' => 'honorific-prefix',
            ],
            'firstName' => [
                'label' => Craft::t('formable', 'First name'),
                'autocomplete' => 'given-name',
            ],
            'middleName' => [
                'label' => Craft::t('formable', 'Middle name'),
                'enabled' => false,
                'autocomplete' => 'additional-name',
            ],
            'lastName' => [
                'label' => Craft::t('formable', 'Last name'),
                'autocomplete' => 'family-name',
            ],
        ];
    }
}
