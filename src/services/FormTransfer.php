<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\elements\Form;
use bytesof\formable\models\FormImportResult;
use bytesof\formable\models\FormSettings;
use bytesof\formable\Plugin;
use Craft;
use DateTime;
use DateTimeInterface;
use yii\base\Component;

/**
 * Owns the on-disk form format - the export/import the `formable/forms` console
 * commands drive for a dev→prod deployment workflow.
 *
 * The format is defined once in {@see serializeForm()}, and the read-back is
 * split into a database-free {@see parseImport()} (so the round-trip is
 * unit-testable, and so `--dry-run` can report without writing) and an
 * {@see applyImport()} that saves.
 *
 * This is deliberately not part of {@see Forms}, which is CRUD and lookup. A
 * file format changes for its own reasons - a new export key, a version bump, a
 * migration for an older file - and none of them are reasons for how a form is
 * fetched or saved to change.
 *
 * @internal
 */
final class FormTransfer extends Component
{
    /**
     * Bumped when the export shape changes incompatibly, so a newer file
     * doesn't import silently wrong into an older plugin.
     *
     * An export file is the one copy of a layout that a migration can't reach,
     * so this number is also the layout's version outside the database. See
     * 0110 for what a bump has to ship with.
     */
    public const EXPORT_FORMAT_VERSION = 1;

    private ?Forms $_forms = null;

    private ?Layout $_layout = null;

    private ?Notifications $_notifications = null;

    /**
     * Overrides the collaborators the transfer runs against.
     *
     * These exist so the round-trip can be unit tested without a booted plugin;
     * at runtime each resolves from the plugin instance. Mirrors
     * {@see Layout::setFields()} / {@see Conditions::setFields()}.
     */
    public function setForms(Forms $forms): void
    {
        $this->_forms = $forms;
    }

    public function getForms(): Forms
    {
        return $this->_forms ??= Plugin::getInstance()->getForms();
    }

    public function setLayout(Layout $layout): void
    {
        $this->_layout = $layout;
    }

    public function getLayout(): Layout
    {
        return $this->_layout ??= Plugin::getInstance()->getLayout();
    }

    public function setNotifications(Notifications $notifications): void
    {
        $this->_notifications = $notifications;
    }

    public function getNotifications(): Notifications
    {
        return $this->_notifications ??= Plugin::getInstance()->getNotifications();
    }

    /**
     * A form's full definition as a portable, secret-free array - the payload
     * the `forms/export` console command writes and `forms/import` reads back.
     *
     * Integration provider secrets live on the global integration records, not
     * on the form, so the per-form config (mappings keyed by handle) is already
     * safe to carry. Notification rows come along, stripped of their
     * install-specific ids.
     *
     * @return array<string, mixed>
     */
    public function exportForm(Form $form): array
    {
        $notifications = [];

        if ($form->id !== null) {
            foreach ($this->getNotifications()->getFormNotifications($form->id) as $notification) {
                $config = $notification->toConfig();
                // Ids are meaningless in another install - the importer recreates
                // rows against the target form.
                unset($config['id']);
                $notifications[] = $config;
            }
        }

        return $this->serializeForm(
            (string)$form->title,
            (string)$form->handle,
            (bool)$form->enabled,
            $form->getFormSettings(),
            $form->getPages(),
            $notifications,
            $form->getTranslations(),
        );
    }

    /**
     * Assembles the on-disk export shape from a form's parts.
     *
     * Kept separate from {@see exportForm()} so the format has a single
     * definition the round-trip test can reach without a saved element.
     *
     * @param array<int, array<string, mixed>> $pages
     * @param array<int, array<string, mixed>> $notifications
     * @param array<mixed> $translations per-site string overrides, keyed by site UID
     * @return array<string, mixed>
     */
    public function serializeForm(
        string $title,
        string $handle,
        bool $enabled,
        FormSettings $settings,
        array $pages,
        array $notifications,
        array $translations,
        ?DateTimeInterface $exportedAt = null,
    ): array {
        $exportedAt ??= new DateTime();

        return [
            'formatVersion' => self::EXPORT_FORMAT_VERSION,
            'exportedAt' => $exportedAt->format(DateTimeInterface::ATOM),
            'form' => [
                'title' => $title,
                'handle' => $handle,
                'enabled' => $enabled,
                'settings' => $settings->toArray(),
                'pages' => array_values($pages),
                'notifications' => array_values($notifications),
                'translations' => $translations,
            ],
        ];
    }

    /**
     * Parses and validates an import payload into the pieces a save needs,
     * without touching the database.
     *
     * The layout is re-normalized and every part re-validated through the same
     * services the builder's save path uses, so a hand-edited or downgraded
     * file can't smuggle in unknown fields or invalid settings. Kept
     * database-free so the export→import round-trip is unit-testable and so the
     * console command can preview (`--dry-run`) before writing.
     *
     * @param array<string, mixed> $payload
     */
    public function parseImport(array $payload): FormImportResult
    {
        // The exporter nests the form under `form`; accept a bare form object
        // too, so a hand-written file needn't carry the wrapper.
        $form = is_array($payload['form'] ?? null) ? $payload['form'] : $payload;

        $title = self::stringValue($form['title'] ?? null);
        $handle = self::stringValue($form['handle'] ?? null);
        $enabled = (bool)($form['enabled'] ?? true);

        $versionError = $this->formatVersionError($payload['formatVersion'] ?? null);

        // A file in a shape this release can't read is refused before any of it
        // is parsed, because its pages and settings would be read in the wrong
        // shape and could pass validation anyway.
        if ($versionError !== null) {
            return new FormImportResult(false, $title, $handle, $enabled, new FormSettings(), [], [], [], [$versionError]);
        }

        $settings = new FormSettings();
        $settings->setAttributes(is_array($form['settings'] ?? null) ? $form['settings'] : [], false);

        $pages = $this->getLayout()->normalizePages($form['pages'] ?? null);

        // Carried opaquely: keyed by site UID so it survives another install's
        // site ID numbering, and Translations already reads every malformed or
        // unknown-site entry as "no override", so there is nothing to reject here.
        $translations = is_array($form['translations'] ?? null) ? $form['translations'] : [];

        $notifications = [];

        foreach (is_array($form['notifications'] ?? null) ? $form['notifications'] : [] as $config) {
            if (!is_array($config)) {
                continue;
            }

            // Ids and form scoping are install-specific - drop them so an import
            // can never update another form's notification row by id.
            unset($config['id'], $config['formId']);
            $notifications[] = $config;
        }

        $errors = [];

        if ($handle === '') {
            $errors[] = Craft::t('formable', 'The import is missing a form handle.');
        }

        if ($title === '') {
            $errors[] = Craft::t('formable', 'The import is missing a form title.');
        }

        $layoutErrors = $this->getLayout()->validatePages($pages);

        if ($this->getLayout()->hasErrors($layoutErrors)) {
            $errors[] = Craft::t('formable', 'The form layout is invalid.');

            foreach ($layoutErrors['general'] as $message) {
                $errors[] = $message;
            }
        }

        if (!$settings->validate()) {
            foreach ($settings->getFirstErrors() as $message) {
                $errors[] = $message;
            }
        }

        if ($this->getNotifications()->validateNotifications($notifications, $this->getLayout()->getFieldHandles($pages)) !== []) {
            $errors[] = Craft::t('formable', 'One or more notifications are invalid.');
        }

        return new FormImportResult(
            $errors === [],
            $title,
            $handle,
            $enabled,
            $settings,
            $pages,
            $notifications,
            $translations,
            $errors,
        );
    }

    /**
     * Writes a parsed import, upserting by handle: an existing form is updated
     * in place, a new one created. The returned form carries any save errors,
     * so a caller checks {@see Form::hasErrors()} rather than a bare bool.
     *
     * Assumes the result is already valid ({@see parseImport()} having reported
     * so) - element validation still runs to catch what a bootless parse can't,
     * chiefly handle uniqueness against a new form.
     */
    public function applyImport(FormImportResult $result): Form
    {
        $forms = $this->getForms();
        $form = $forms->getFormByHandle($result->handle) ?? new Form();

        $form->title = $result->title;
        $form->handle = $result->handle;
        $form->enabled = $result->enabled;
        $form->setPages($result->pages);
        $form->setSettings($result->settings->toArray());
        $form->setTranslations($result->translations);

        $forms->saveFormWithNotifications($form, $result->notifications);

        return $form;
    }

    /**
     * Why a file's `formatVersion` can't be imported, or null when it can.
     *
     * A file without one is read as version 1: that is what every export
     * written before the check existed carries, and what a hand-written bare
     * form object means.
     */
    private function formatVersionError(mixed $version): ?string
    {
        $version ??= 1;

        if (!is_int($version) || $version < 1) {
            return Craft::t('formable', 'The import has an unrecognised format version.');
        }

        if ($version > self::EXPORT_FORMAT_VERSION) {
            return Craft::t('formable', 'The import was exported by a newer version of Formable (format version {version}). Update Formable on this site to import it.', [
                'version' => $version,
            ]);
        }

        return null;
    }

    private static function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
