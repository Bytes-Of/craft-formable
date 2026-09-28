<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\CompositeFormField;
use Craft;

/**
 * Postal address.
 *
 * @internal
 */
final class Address extends CompositeFormField
{
    public static function displayName(): string
    {
        return Craft::t('formable', 'Address');
    }

    public static function icon(): string
    {
        return 'location-dot';
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function defineSubFields(): array
    {
        return [
            'address1' => [
                'label' => Craft::t('formable', 'Address'),
                'autocomplete' => 'address-line1',
            ],
            'address2' => [
                'label' => Craft::t('formable', 'Address line 2'),
                'enabled' => false,
                'autocomplete' => 'address-line2',
            ],
            'city' => [
                'label' => Craft::t('formable', 'City'),
                'autocomplete' => 'address-level2',
            ],
            'state' => [
                'label' => Craft::t('formable', 'State / region'),
                'autocomplete' => 'address-level1',
            ],
            'zip' => [
                'label' => Craft::t('formable', 'ZIP / postal code'),
                'autocomplete' => 'postal-code',
            ],
            'country' => [
                'label' => Craft::t('formable', 'Country'),
                'type' => 'country',
                'autocomplete' => 'country-name',
            ],
        ];
    }

    protected function valueSeparator(): string
    {
        return ', ';
    }
}
