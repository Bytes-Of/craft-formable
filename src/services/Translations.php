<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\base\FormField;
use bytesof\formable\elements\Form;
use bytesof\formable\models\FormSettings;
use bytesof\formable\Plugin;
use Craft;
use InvalidArgumentException;
use yii\base\Component;

/**
 * Applies per-site overrides of author-entered form strings - page and field
 * labels, instructions, option labels, and the nine translatable settings
 * messages - against a form's `translations` blob.
 *
 * {@see localizePages()} overlays a site's field-level overrides onto the raw
 * layout **array**, before {@see Fields::createField()} hydrates it into
 * {@see FormField} instances - not by mutating an already-hydrated field.
 * Those instances come from {@see Fields::getFieldMap()}, cached per request
 * by a hash of the array it was built from ([[0018]]); overlaying first means
 * a site's translated layout simply hashes to its own cache entry; overlaying
 * after would mean writing one site's override into an object the same
 * request's cache might hand back for a *different* site the moment two sites
 * of one form render in the same request. This is the correction for 0083's
 * "resolution is a lookup, on demand" section - see decision 0086.
 *
 * `localizeSettings()` takes the same approach for the nine settings strings,
 * returning a cloned {@see FormSettings} with the overrides already applied,
 * for the same reason: every consumer downstream (a template, `Rendering`'s
 * own methods) reads a plain property and never has to know a lookup happened.
 *
 * `settingString()` is the exception, kept for callers - the controllers -
 * that resolve one setting string in isolation rather than needing the whole
 * localized model.
 *
 * @internal
 */
final class Translations extends Component
{
    /**
     * The settings-blob keys {@see settingString()}/{@see resolveSettingString()}
     * will resolve an override for. Anything else is a programmer error, caught
     * here rather than silently returning the base value for a typo'd key.
     */
    public const TRANSLATABLE_SETTINGS = [
        'successMessage',
        'successDetails',
        'errorMessage',
        'closedMessage',
        'submitButtonLabel',
        'nextButtonLabel',
        'backButtonLabel',
        'saveAndResumeLabel',
        'reviewPageLabel',
    ];

    private ?bool $_enabled = null;

    /**
     * Overrides the edition gate, so both the Pro and the bypassed Lite paths
     * can be unit tested without a booted plugin. Mirrors
     * {@see Conditions::setEnabled()}.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->_enabled = $enabled;
    }

    /**
     * Reads the active edition unless {@see setEnabled()} has overridden it.
     */
    public function isEnabled(): bool
    {
        return $this->_enabled ??= Plugin::getInstance()->isPro();
    }

    /**
     * `$form->getPages()`, with `$siteId`'s (or the current site's) overrides
     * already overlaid - a page's label, and each field's translatable keys.
     * On Lite, or when the site has nothing stored, this is `$form->getPages()`
     * itself, so {@see Fields::getFieldMap()} serves the very same cache entry
     * (and objects) a plain, translation-free render would.
     *
     * @return array<int, mixed>
     */
    public function localizePages(Form $form, ?int $siteId = null): array
    {
        return $this->resolvePages($form->getPages(), $form->getTranslations(), $this->siteUid($siteId));
    }

    /**
     * {@see localizePages()}, against raw pages/translations arrays rather
     * than a `Form` - the pure core, so it can be unit tested without a
     * booted element.
     *
     * @param array<int, mixed> $pages
     * @param array<string, mixed> $translations
     * @return array<int, mixed>
     */
    public function resolvePages(array $pages, array $translations, ?string $siteUid): array
    {
        if (!$this->isEnabled()) {
            return $pages;
        }

        $site = $this->siteOverrides($translations, $siteUid);
        $pageLabels = is_array($site['pages'] ?? null) ? $site['pages'] : [];
        $fieldOverrides = is_array($site['fields'] ?? null) ? $site['fields'] : [];

        if ($pageLabels === [] && $fieldOverrides === []) {
            return $pages;
        }

        $localized = [];

        foreach ($pages as $pageIndex => $page) {
            $localized[$pageIndex] = is_array($page)
                ? $this->localizePage($page, $pageLabels, $fieldOverrides)
                : $page;
        }

        return $localized;
    }

    /**
     * @param array<string, mixed> $page
     * @param array<string, mixed> $pageLabels
     * @param array<string, mixed> $fieldOverrides
     * @return array<string, mixed>
     */
    private function localizePage(array $page, array $pageLabels, array $fieldOverrides): array
    {
        $label = $pageLabels[$page['id'] ?? ''] ?? null;

        if (is_string($label) && $label !== '') {
            $page['label'] = $label;
        }

        $rows = is_array($page['rows'] ?? null) ? $page['rows'] : [];

        $page['rows'] = array_map(
            fn(mixed $row): mixed => is_array($row) ? $this->localizeRow($row, $fieldOverrides) : $row,
            $rows,
        );

        return $page;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $fieldOverrides
     * @return array<string, mixed>
     */
    private function localizeRow(array $row, array $fieldOverrides): array
    {
        $fields = is_array($row['fields'] ?? null) ? $row['fields'] : [];

        $row['fields'] = array_map(
            fn(mixed $field): mixed => is_array($field) ? $this->localizeField($field, $fieldOverrides) : $field,
            $fields,
        );

        return $row;
    }

    /**
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $fieldOverrides
     * @return array<string, mixed>
     */
    private function localizeField(array $layout, array $fieldOverrides): array
    {
        $id = $layout['id'] ?? null;
        $override = is_string($id) ? ($fieldOverrides[$id] ?? null) : null;

        if (!is_array($override) || $override === []) {
            return $layout;
        }

        $type = $layout['type'] ?? null;

        if (!is_string($type) || !is_subclass_of($type, FormField::class)) {
            return $layout;
        }

        return $type::applyTranslation($layout, $override);
    }

    /**
     * A clone of `$form`'s settings with `$siteId`'s (or the current site's)
     * overrides of the nine {@see TRANSLATABLE_SETTINGS} already applied.
     */
    public function localizeSettings(Form $form, ?int $siteId = null): FormSettings
    {
        return $this->resolveSettings($form->getFormSettings(), $form->getTranslations(), $this->siteUid($siteId));
    }

    /**
     * {@see localizeSettings()}, against a raw translations array.
     *
     * @param array<string, mixed> $translations
     */
    public function resolveSettings(FormSettings $settings, array $translations, ?string $siteUid): FormSettings
    {
        $localized = clone $settings;

        foreach (self::TRANSLATABLE_SETTINGS as $key) {
            $localized->$key = $this->resolveSettingString($translations, $key, (string)$settings->$key, $siteUid);
        }

        return $localized;
    }

    /**
     * The overridden value of one of the nine translatable settings strings on
     * `$siteId` (or the current site), falling back to the base value stored
     * on the form's own settings when there is none.
     *
     * Kept for callers - the controllers - that need one setting string in
     * isolation (a flashed success message, say) rather than the whole
     * localized model {@see localizeSettings()} returns.
     *
     * `$key` must be one of {@see TRANSLATABLE_SETTINGS} - anything else is a
     * programmer error, thrown rather than silently ignored.
     */
    public function settingString(Form $form, string $key, ?int $siteId = null): string
    {
        if (!in_array($key, self::TRANSLATABLE_SETTINGS, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a translatable setting.', $key));
        }

        $base = (string)$form->getFormSettings()->$key;

        return $this->resolveSettingString($form->getTranslations(), $key, $base, $this->siteUid($siteId));
    }

    /**
     * The `$key` override on `$siteUid`, or `$base` when there is none.
     *
     * `$base` is passed in rather than read off a `FormSettings` here, so this
     * method - unlike {@see settingString()} - never needs a `Form`.
     *
     * @param array<string, mixed> $translations
     */
    public function resolveSettingString(array $translations, string $key, string $base, ?string $siteUid): string
    {
        if (!in_array($key, self::TRANSLATABLE_SETTINGS, true)) {
            throw new InvalidArgumentException(sprintf('"%s" is not a translatable setting.', $key));
        }

        if (!$this->isEnabled()) {
            return $base;
        }

        $settings = $this->siteOverrides($translations, $siteUid)['settings'] ?? null;
        $override = is_array($settings) ? ($settings[$key] ?? null) : null;

        return is_string($override) && $override !== '' ? $override : $base;
    }

    /**
     * The raw override sub-array stored for `$siteUid` - the `{"pages": ...,
     * "fields": ..., "settings": ...}` shape - or empty when there is none,
     * including when not Pro (no lookup at all past that check).
     *
     * @param array<string, mixed> $translations
     * @return array<string, mixed>
     */
    private function siteOverrides(array $translations, ?string $siteUid): array
    {
        if (!$this->isEnabled() || $siteUid === null) {
            return [];
        }

        $override = $translations[$siteUid] ?? null;

        return is_array($override) ? $override : [];
    }

    /**
     * The UID of `$siteId` (or the current site), or null when it can't be
     * resolved - which reads as "no override" to every caller here, the same
     * defensive posture taken toward bad stored data.
     */
    private function siteUid(?int $siteId): ?string
    {
        $siteId ??= Craft::$app->getSites()->getCurrentSite()->id;

        return Craft::$app->getSites()->getSiteById($siteId)?->uid;
    }
}
