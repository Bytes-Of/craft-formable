<?php

declare(strict_types=1);

namespace bytesof\formable\base;

/**
 * Base for presentational fields (headings, dividers, HTML blocks) that are
 * rendered inside a form but never collect, validate or store a value.
 *
 * @api
 */
abstract class CosmeticFormField extends FormField
{
    public static function group(): string
    {
        return self::GROUP_LAYOUT;
    }

    public static function hasValue(): bool
    {
        return false;
    }

    public function normalizeValue(mixed $value): mixed
    {
        return null;
    }

    public function serializeValue(mixed $value): mixed
    {
        return null;
    }

    public function valueToString(mixed $value): string
    {
        return '';
    }
}
