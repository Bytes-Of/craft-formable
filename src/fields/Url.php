<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use Craft;

/**
 * Website / URL input.
 *
 * @internal
 */
final class Url extends Text
{
    public string $inputType = 'url';
    public string $autocomplete = 'url';

    public static function displayName(): string
    {
        return Craft::t('formable', 'Website');
    }

    public static function icon(): string
    {
        return 'link';
    }

    public function normalizeValue(mixed $value): mixed
    {
        $value = parent::normalizeValue($value);

        // Match the validator's defaultScheme so what we store is what we
        // validated - otherwise "example.com" passes but links out broken.
        if (is_string($value) && $value !== '' && !preg_match('/^[a-z][a-z0-9+.-]*:/i', $value)) {
            $value = "https://$value";
        }

        return $value;
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineValueValidationRules(): array
    {
        return array_merge(parent::defineValueValidationRules(), [
            // defaultScheme lets submitters type "example.com" and still pass.
            ['url', 'defaultScheme' => 'https'],
        ]);
    }

    /**
     * @return array{type: string, maxLength?: ?int}
     */
    protected function defineValidationSpec(): array
    {
        return array_merge(parent::defineValidationSpec(), ['type' => 'url']);
    }
}
