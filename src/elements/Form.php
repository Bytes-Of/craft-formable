<?php

declare(strict_types=1);

namespace bytesof\formable\elements;

use bytesof\formable\db\Table;
use bytesof\formable\elements\db\FormQuery;
use bytesof\formable\elements\db\SubmissionQuery;
use bytesof\formable\jobs\CascadeSubmissions;
use bytesof\formable\jobs\RenameFieldHandles;
use bytesof\formable\models\FormSettings;
use bytesof\formable\Plugin;
use bytesof\formable\records\FormRecord;
use Craft;
use craft\base\Element;
use craft\db\Query;
use craft\elements\actions\Delete;
use craft\elements\actions\Restore;
use craft\elements\actions\SetStatus;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\validators\HandleValidator;

/**
 * Form element. Pages/fields layout and per-form settings are stored as JSON
 * on the formable_forms row - no Craft field-layout coupling.
 *
 * @api
 */
final class Form extends Element
{
    /**
     * A cascade above this many submissions is queued rather than run inline,
     * so deleting or restoring a large form doesn't hold up the CP request.
     */
    private const CASCADE_SYNC_LIMIT = 50;

    public ?string $handle = null;
    public ?int $defaultStatusId = null;

    /** @var array<mixed>|null */
    private ?array $_pages = null;

    /** @var array<mixed>|null */
    private ?array $_settings = null;

    /** @var array<mixed>|null */
    private ?array $_translations = null;

    /** @var array<string, string> */
    private array $_renamedHandles = [];

    public static function displayName(): string
    {
        return Craft::t('formable', 'Form');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('formable', 'form');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('formable', 'Forms');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('formable', 'forms');
    }

    public static function refHandle(): string
    {
        return 'formableForm';
    }

    public static function hasTitles(): bool
    {
        return true;
    }

    public static function hasStatuses(): bool
    {
        return true;
    }

    public static function isLocalized(): bool
    {
        return false;
    }

    public static function trackChanges(): bool
    {
        return false;
    }

    /**
     * @return FormQuery<int, self>
     */
    public static function find(): FormQuery
    {
        return new FormQuery(static::class);
    }

    /**
     * @return array<int|string, mixed>
     */
    protected static function defineSources(string $context): array
    {
        return [
            [
                'key' => '*',
                'label' => Craft::t('formable', 'All forms'),
                'defaultSort' => ['title', 'asc'],
            ],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected static function defineActions(string $source): array
    {
        return [
            SetStatus::class,
            [
                'type' => Delete::class,
                'confirmationMessage' => Craft::t('formable', 'Are you sure you want to delete the selected forms? Their submissions will move to the trash too, and come back if the form is restored.'),
                'successMessage' => Craft::t('formable', 'Forms deleted.'),
            ],
            [
                'type' => Restore::class,
                'successMessage' => Craft::t('formable', 'Forms restored.'),
                'partialSuccessMessage' => Craft::t('formable', 'Some forms restored.'),
                'failMessage' => Craft::t('formable', 'Forms not restored.'),
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected static function defineSortOptions(): array
    {
        return [
            [
                'label' => Craft::t('app', 'Title'),
                'orderBy' => 'title',
                'attribute' => 'title',
            ],
            [
                'label' => Craft::t('formable', 'Handle'),
                'orderBy' => 'formable_forms.handle',
                'attribute' => 'handle',
            ],
            [
                'label' => Craft::t('app', 'Date Created'),
                'orderBy' => 'elements.dateCreated',
                'attribute' => 'dateCreated',
                'defaultDir' => 'desc',
            ],
            [
                'label' => Craft::t('app', 'Date Updated'),
                'orderBy' => 'elements.dateUpdated',
                'attribute' => 'dateUpdated',
                'defaultDir' => 'desc',
            ],
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected static function defineTableAttributes(): array
    {
        return [
            'handle' => ['label' => Craft::t('formable', 'Handle')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        return ['handle', 'dateCreated'];
    }

    /**
     * @return array<int, string>
     */
    protected static function defineSearchableAttributes(): array
    {
        return ['title', 'handle'];
    }

    protected function attributeHtml(string $attribute): string
    {
        if ($attribute === 'handle') {
            return $this->handle !== null
                ? Html::tag('code', Html::encode($this->handle))
                : '';
        }

        return parent::attributeHtml($attribute);
    }

    /**
     * @return array<mixed>
     *
     * @internal
     */
    public function getPages(): array
    {
        return $this->_pages ?? [];
    }

    /**
     * @param array<mixed>|string|null $pages
     *
     * @internal
     */
    public function setPages(array|string|null $pages): void
    {
        if (is_string($pages)) {
            $pages = Json::decodeIfJson($pages);
        }

        $this->_pages = is_array($pages) ? $pages : null;
    }

    /**
     * @return array<mixed>
     *
     * @internal
     */
    public function getSettings(): array
    {
        return $this->_settings ?? [];
    }

    /**
     * @param array<mixed>|string|null $settings
     *
     * @internal
     */
    public function setSettings(array|string|null $settings): void
    {
        if (is_string($settings)) {
            $settings = Json::decodeIfJson($settings);
        }

        $this->_settings = is_array($settings) ? $settings : null;
    }

    /**
     * @return array<mixed>
     *
     * @internal
     */
    public function getTranslations(): array
    {
        return $this->_translations ?? [];
    }

    /**
     * @param array<mixed>|string|null $translations
     *
     * @internal
     */
    public function setTranslations(array|string|null $translations): void
    {
        if (is_string($translations)) {
            $translations = Json::decodeIfJson($translations);
        }

        $this->_translations = is_array($translations) ? $translations : null;
    }

    /**
     * The `settings` blob as a validated model. Unknown keys in the stored
     * JSON are dropped, so a downgrade can't resurrect a removed setting.
     *
     * @internal
     */
    public function getFormSettings(): FormSettings
    {
        $settings = new FormSettings();
        $settings->setAttributes($this->getSettings(), false);

        return $settings;
    }

    /**
     * @inheritdoc
     *
     * `handle` is Formable's own property, not one of the base element
     * attributes {@see \craft\base\ElementTrait} already marks safe - and a
     * success/error/closed message routinely names it (`{handle}`,
     * `{{ object.handle }}`). See internal/decisions/0099.
     *
     * @internal
     */
    public function propertyAllowedInSandbox(string $property): bool
    {
        return $property === 'handle' || parent::propertyAllowedInSandbox($property);
    }

    /**
     * Whether the form is open to new submissions right now.
     *
     * Folds the three reasons a form can be shut into one gate the submission
     * paths share: it's been trashed, it's been switched off in the CP, or -
     * on Pro - the current moment falls outside its schedule window. Lite never
     * enforces the window, so a scheduled form there behaves as an unscheduled
     * one. This is the single source of truth for "can this form take a post",
     * consulted by the front-end controller, the GraphQL mutation, and the
     * renderer (which shows the closed message when it returns false).
     */
    public function isAcceptingSubmissions(): bool
    {
        if ($this->trashed || !$this->enabled) {
            return false;
        }

        // Scheduled availability and submission limits are Pro features; neither
        // is consulted in Lite.
        if (Plugin::getInstance()->isPro()) {
            $settings = $this->getFormSettings();

            if (!$settings->isWithinScheduleWindow()) {
                return false;
            }

            // The count query is only worth running once the cap is actually
            // on; the pure comparison then lives on the settings model.
            if ($settings->limitSubmissions && $settings->hasReachedSubmissionLimit($this->storedSubmissionCount($settings->maxSubmissions))) {
                return false;
            }
        }

        return true;
    }

    /**
     * How many submissions count against the form's cap right now.
     *
     * Counts completed, non-spam submissions live at the moment of the check.
     * That makes the cap a soft ceiling, not a hard quota: two submissions
     * racing the same near-full form can both pass this count before either
     * saves and slip the total one or two over. Closing that gap would mean
     * locking the form's rows for every submit, which isn't worth it at v1
     * volumes - the limit is a "stop collecting around here" control, not a
     * licence seat.
     *
     * Counts no further than `$cap`, so the answer is never more than the cap:
     * this runs on every render of a capped form, and a form whose cap was
     * raised past a large history would otherwise count all of it to learn it
     * is over ([[0112-submission-indexes-follow-the-measured-query-shapes]]).
     */
    private function storedSubmissionCount(int $cap): int
    {
        // A form that hasn't been saved yet has no submissions and no id to
        // count against.
        if ($this->id === null) {
            return 0;
        }

        $cap = max(1, $cap);
        $query = Submission::find()
            ->formId($this->id)
            ->isIncomplete(false)
            ->isSpam(false)
            // Every stored submission counts toward the cap regardless of its
            // workflow status - a rejected one was still collected.
            ->status(null)
            // Unordered: which row is the cap-th doesn't matter, and an order
            // would sort the whole form to find out.
            ->orderBy(null);

        // Counting ignores a limit, so ask whether the cap-th row exists; when
        // it doesn't, the real count is under the cap and cheap to take.
        if ((clone $query)->offset($cap - 1)->exists()) {
            return $cap;
        }

        return (int)$query->count();
    }

    public function canView(User $user): bool
    {
        return $user->can('formable:viewForms');
    }

    public function canSave(User $user): bool
    {
        return $user->can('formable:saveForms');
    }

    public function canDelete(User $user): bool
    {
        return $user->can('formable:deleteForms');
    }

    public function canDuplicate(User $user): bool
    {
        return $user->can('formable:saveForms');
    }

    protected function cpEditUrl(): ?string
    {
        return $this->id !== null
            ? UrlHelper::cpUrl("formable/forms/$this->id")
            : null;
    }

    public function getPostEditUrl(): string
    {
        return UrlHelper::cpUrl('formable/forms');
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['handle'], 'required'];
        $rules[] = [['handle'], 'string', 'max' => 255];
        $rules[] = [
            ['handle'],
            HandleValidator::class,
            'reservedWords' => ['id', 'uid', 'title', 'form', 'submission', 'dateCreated', 'dateUpdated'],
        ];
        $rules[] = [['handle'], 'validateHandleUnique', 'skipOnEmpty' => true];
        $rules[] = [['defaultStatusId'], 'number', 'integerOnly' => true];

        return $rules;
    }

    public function validateHandleUnique(string $attribute): void
    {
        if ($this->handleTakenByAnotherForm($this->handle, trashed: false)) {
            $this->addError($attribute, Craft::t('formable', 'A form with the handle “{handle}” already exists.', [
                'handle' => $this->handle,
            ]));
        }
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = null;

            if (!$isNew) {
                $record = FormRecord::findOne($this->id);
            }

            if ($record === null) {
                $record = new FormRecord();
                $record->id = $this->id;
            }

            $this->_renamedHandles = $isNew ? [] : $this->detectRenamedHandles($record);

            $record->handle = $this->handle;
            // Assign arrays directly - Craft's ActiveRecord JSON-encodes
            // json() columns on save (encoding a string here double-encodes).
            $record->pages = $this->getPages();
            $record->settings = $this->getSettings();
            $record->translations = $this->getTranslations();
            $record->defaultStatusId = $this->defaultStatusId;
            $record->save(false);

            $this->queueHandleMigration();
        }

        parent::afterSave($isNew);
    }

    /**
     * The field handles the last save changed, as `oldHandle => newHandle`.
     *
     * Empty until a save has run. The builder's save response reads it to say
     * what was migrated.
     *
     * @return array<string, string>
     *
     * @internal
     */
    public function getRenamedHandles(): array
    {
        return $this->_renamedHandles;
    }

    /**
     * Compared here, against the row about to be overwritten, rather than in
     * the controller: this is the one point every save path - the builder, a
     * console import, a project-config apply - passes through with the old
     * layout still in hand.
     *
     * @return array<string, string>
     */
    private function detectRenamedHandles(FormRecord $record): array
    {
        $stored = $record->pages;
        $oldPages = is_string($stored) ? Json::decodeIfJson($stored) : $stored;

        if (!is_array($oldPages) || $oldPages === []) {
            return [];
        }

        return Plugin::getInstance()->getLayout()->detectRenames($oldPages, $this->getPages());
    }

    /**
     * Answers are stored under their field's handle, so a rename leaves every
     * stored submission's answer under a key nothing reads any more unless it
     * is re-keyed. Queued rather than run inline: a form can have any number
     * of submissions and the save is a builder request.
     */
    private function queueHandleMigration(): void
    {
        if ($this->_renamedHandles === [] || $this->id === null) {
            return;
        }

        $hasSubmissions = (new Query())
            ->from(Table::SUBMISSIONS)
            ->where(['formId' => $this->id])
            ->exists();

        if (!$hasSubmissions) {
            return;
        }

        Craft::$app->getQueue()->push(new RenameFieldHandles([
            'formId' => $this->id,
            'renames' => $this->_renamedHandles,
        ]));
    }

    /**
     * Moves the form's own submissions to the trash alongside it, so none
     * outlive it and escape retention or view. Mirrors Craft's own
     * owner/nested-element cascade: each submission is marked
     * `deletedWithOwner`, which {@see afterRestore()} reads to bring back only
     * the ones that were trashed for this reason, not ones a user had already
     * trashed independently.
     *
     * Runs in `beforeDelete()`, not `afterDelete()`: a hard delete removes the
     * form's own `elements` row immediately, and `formable_submissions.formId`
     * cascades from `formable_forms.id`, so by `afterDelete()` a hard-deleted
     * form's submissions can no longer be found by it. A hard delete of the
     * form (a final purge, not a trip to the trash) cascades as a hard delete
     * too - otherwise a purged form would leave its submissions behind as live
     * elements with no form to belong to.
     */
    public function beforeDelete(): bool
    {
        if (!parent::beforeDelete()) {
            return false;
        }

        $this->cascadeSubmissions(delete: true);

        return true;
    }

    /**
     * Uniqueness ignores the trash, so a new form can take a trashed form's
     * handle - and Craft's restore then fails that form's essentials
     * validation with nothing but "Forms not restored." to show for it. The
     * trashed form can't be edited to fix that, so it comes back under a free
     * variant of its handle instead; the live form keeps the one templates
     * already resolve to.
     */
    public function beforeRestore(): bool
    {
        if (!parent::beforeRestore()) {
            return false;
        }

        if ($this->id !== null && $this->handleTakenByAnotherForm($this->handle, trashed: false)) {
            $this->handle = $this->freeHandleVariant((string)$this->handle);
            Db::update(Table::FORMS, ['handle' => $this->handle], ['id' => $this->id]);
        }

        return true;
    }

    private function handleTakenByAnotherForm(?string $handle, ?bool $trashed): bool
    {
        $query = self::find()->handle($handle)->status(null)->trashed($trashed);

        if ($this->id !== null) {
            $query->id("not $this->id");
        }

        return $query->exists();
    }

    /**
     * Checked against trashed forms too, so a second restore can't be handed
     * a handle another trashed form would then clash with.
     */
    private function freeHandleVariant(string $handle): string
    {
        for ($suffix = 2; ; $suffix++) {
            $candidate = substr($handle, 0, 255 - strlen((string)$suffix)) . $suffix;

            if (!$this->handleTakenByAnotherForm($candidate, trashed: null)) {
                return $candidate;
            }
        }
    }

    /**
     * Restores whichever of the form's submissions were trashed alongside it
     * by {@see beforeDelete()}.
     */
    public function afterRestore(): void
    {
        $this->cascadeSubmissions(delete: false);

        parent::afterRestore();
    }

    private function cascadeSubmissions(bool $delete): void
    {
        if ($this->id === null) {
            return;
        }

        $ids = $this->cascadeSubmissionsQuery($delete)->ids();

        if ($ids === []) {
            return;
        }

        // A hard delete's own `formable_submissions` rows cascade away the
        // moment the form's `elements` row is removed - before a queued job
        // could ever reach them by id - so only a soft delete (a trip to the
        // trash, where nothing is removed yet) is safe to defer.
        if (count($ids) > self::CASCADE_SYNC_LIMIT && !($delete && $this->hardDelete)) {
            Craft::$app->getQueue()->push(new CascadeSubmissions([
                'submissionIds' => $ids,
                'restore' => !$delete,
            ]));

            return;
        }

        $elements = Craft::$app->getElements();

        foreach ($this->cascadeSubmissionsQuery($delete)->id($ids)->all() as $submission) {
            if ($delete) {
                $submission->deletedWithOwner = true;
                $elements->deleteElement($submission, $this->hardDelete);
            } else {
                $elements->restoreElement($submission);
            }
        }
    }

    /**
     * Spam and save-and-resume drafts hold answers too, and the query's
     * defaults hide both - left out, a trashed form kept them live and a
     * purged one left their `elements` rows behind.
     *
     * @return SubmissionQuery<int, Submission>
     */
    private function cascadeSubmissionsQuery(bool $delete): SubmissionQuery
    {
        $query = Submission::find()->formId($this->id)->status(null)->isIncomplete(null)->isSpam(null);

        return $delete ? $query : $query->trashed()->andWhere(['elements.deletedWithOwner' => true]);
    }
}
