<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\RelationFormField;
use Craft;
use craft\elements\Category;

/**
 * Relates the submission to one or more categories.
 *
 * @internal
 */
final class Categories extends RelationFormField
{
    public static function displayName(): string
    {
        return Craft::t('formable', 'Categories');
    }

    public static function icon(): string
    {
        return 'folder-tree';
    }

    /**
     * @return class-string<Category>
     */
    public static function elementType(): string
    {
        return Category::class;
    }
}
