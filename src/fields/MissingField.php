<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\CosmeticFormField;
use Craft;
use craft\base\MissingComponentInterface;
use craft\base\MissingComponentTrait;

/**
 * Stand-in for a field whose class can no longer be loaded - typically a
 * third-party field type from a plugin that has been uninstalled.
 *
 * Rendering a placeholder rather than throwing keeps a form (and its existing
 * submissions) editable while the missing plugin is restored.
 *
 * @internal
 */
final class MissingField extends CosmeticFormField implements MissingComponentInterface
{
    use MissingComponentTrait;

    public static function displayName(): string
    {
        return Craft::t('formable', 'Missing field');
    }

    public static function isSelectable(): bool
    {
        return false;
    }

    public static function icon(): string
    {
        return 'alert';
    }

    /**
     * A placeholder for a class that no longer loads has nothing meaningful
     * for a site override to change.
     *
     * @return array<int, string>
     */
    public static function translatableProperties(): array
    {
        return [];
    }
}
