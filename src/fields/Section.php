<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\CosmeticFormField;
use Craft;

/**
 * Boundary between groups of fields.
 *
 * Placed alone in a row, it never renders itself - `_page.twig` reads
 * {@see startsGroup()} to split the page's rows into groups there instead,
 * each wrapped in a bordered `.formable-group` once a page has more than one.
 * That's what makes it a *grouping* primitive rather than a plain divider:
 * see [[frontend-design-system-2]] 1.11 and
 * `internal/decisions/0042-section-is-a-group-boundary-not-a-divider.md`.
 * The rare case of a Section sharing a row with other fields (not offered by
 * the builder, but not rejected by validation either) still falls through to
 * `fields/section.twig`'s `<hr>`.
 *
 * @internal
 */
final class Section extends CosmeticFormField
{
    public static function displayName(): string
    {
        return Craft::t('formable', 'Section Divider');
    }

    public static function icon(): string
    {
        return 'minus';
    }

    /**
     * The label only names the divider in the builder - it is never rendered,
     * so authors shouldn't be forced to write one.
     */
    public static function requiresLabel(): bool
    {
        return false;
    }

    public function startsGroup(): bool
    {
        return true;
    }

    /**
     * A divider's label is never rendered - see {@see requiresLabel()} -
     * so there is nothing here for a site override to change.
     *
     * @return array<int, string>
     */
    public static function translatableProperties(): array
    {
        return [];
    }
}
