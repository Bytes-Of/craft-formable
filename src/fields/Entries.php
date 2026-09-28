<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\RelationFormField;
use Craft;
use craft\elements\Entry;

/**
 * Relates the submission to one or more entries.
 *
 * @internal
 */
final class Entries extends RelationFormField
{
    public static function displayName(): string
    {
        return Craft::t('formable', 'Entries');
    }

    public static function icon(): string
    {
        return 'newspaper';
    }

    /**
     * @return class-string<Entry>
     */
    public static function elementType(): string
    {
        return Entry::class;
    }
}
