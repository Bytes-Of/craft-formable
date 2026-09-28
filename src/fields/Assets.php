<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\RelationFormField;
use Craft;
use craft\elements\Asset;

/**
 * Relates the submission to one or more assets.
 *
 * Withheld from the palette ({@see isSelectable()}) pending a decision on
 * whether an anonymous form may enumerate an install's files at all - see
 * internal/decisions/0085-what-an-anonymous-form-may-enumerate.md. A layout
 * saved before this still hydrates and renders (as an empty picker, since
 * {@see getSelectableElements()} never queries), the same `MissingField`
 * shape uses to keep old layouts editable without offering the field again.
 *
 * @internal
 */
final class Assets extends RelationFormField
{
    public static function displayName(): string
    {
        return Craft::t('formable', 'Assets');
    }

    public static function icon(): string
    {
        return 'image';
    }

    public static function isSelectable(): bool
    {
        return false;
    }

    /**
     * @return class-string<Asset>
     */
    public static function elementType(): string
    {
        return Asset::class;
    }

    /**
     * @return array<int, never>
     */
    public function getSelectableElements(): array
    {
        return [];
    }
}
