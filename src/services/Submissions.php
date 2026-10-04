<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\base\FormField;
use bytesof\formable\db\Table;
use bytesof\formable\elements\db\SubmissionQuery;
use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\events\SubmissionErrorEvent;
use bytesof\formable\events\SubmissionEvent;
use bytesof\formable\fields\FileUpload;
use bytesof\formable\jobs\PurgeSubmissions;
use bytesof\formable\models\FormSettings;
use bytesof\formable\models\SpamContext;
use bytesof\formable\Plugin;
use bytesof\formable\records\SubmissionHistoryRecord;
use bytesof\formable\records\SubmissionNoteRecord;
use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use DateInterval;
use DateTime;
use Throwable;
use yii\base\Component;

/**
 * The submission pipeline: turn posted values into a validated Submission and
 * persist it.
 *
 * Every mutation is bracketed by events - this is the extensibility surface
 * spam checks, integrations and notifications hang off in later milestones, so
 * the ordering here is load-bearing:
 *
 *   beforeSubmit → validate → beforeSaveSubmission → save → afterSaveSubmission → afterSubmit
 *
 * @internal
 */
final class Submissions extends Component
{
    /**
     * @event SubmissionEvent Fired before a submission is processed, after its
     * values are populated but before they are validated. Cancelable.
     *
     * @api
     */
    public const EVENT_BEFORE_SUBMIT = 'beforeSubmit';

    /**
     * @event SubmissionEvent Fired after a submission has been accepted and
     * (unless the form stores nothing) saved.
     *
     * @api
     */
    public const EVENT_AFTER_SUBMIT = 'afterSubmit';

    /**
     * @event SubmissionEvent Fired before a submission is written. Cancelable.
     *
     * @api
     */
    public const EVENT_BEFORE_SAVE_SUBMISSION = 'beforeSaveSubmission';

    /**
     * @event SubmissionEvent Fired after a submission is written.
     *
     * @api
     */
    public const EVENT_AFTER_SAVE_SUBMISSION = 'afterSaveSubmission';

    /**
     * @event SubmissionErrorEvent Fired whenever a submission is rejected.
     *
     * @api
     */
    public const EVENT_SUBMISSION_ERROR = 'submissionError';

    /**
     * @event SubmissionEvent Fired after an incomplete submission is stored by
     * save-and-resume - either when the submitter parks the form, or when a
     * form they have already parked moves on a page.
     *
     * @api
     */
    public const EVENT_AFTER_SAVE_INCOMPLETE = 'afterSaveIncomplete';

    /**
     * How many submissions one purge call deletes. Small enough to finish well
     * inside a queue job's reservation; the job re-queues itself for the rest.
     */
    public const PURGE_LIMIT = 500;

    private ?Fields $_fields = null;

    private ?Conditions $_conditions = null;

    private ?Spam $_spam = null;

    /**
     * Overrides the field registry, so the value-mapping logic can be unit
     * tested without a booted plugin. Mirrors {@see Layout::setFields()}.
     */
    public function setFields(Fields $fields): void
    {
        $this->_fields = $fields;
    }

    public function getFields(): Fields
    {
        return $this->_fields ??= Plugin::getInstance()->getFields();
    }

    public function setConditions(Conditions $conditions): void
    {
        $this->_conditions = $conditions;
    }

    public function getConditions(): Conditions
    {
        return $this->_conditions ??= Plugin::getInstance()->getConditions();
    }

    public function setSpamService(Spam $spam): void
    {
        $this->_spam = $spam;
    }

    public function getSpam(): Spam
    {
        return $this->_spam ??= Plugin::getInstance()->getSpam();
    }

    /**
     * Loads one submission by id, whatever state it is in.
     *
     * The query hides incomplete and spam submissions by default, which is what
     * an index or a template loop wants and exactly what a lookup by id does
     * not: the caller has already named the row it means, and does its own
     * checking from there. Leaving the defaults on made a multi-page form's
     * progress row - incomplete by definition - invisible to the session that
     * owns it, and left the control panel's spam queue listing rows it could
     * neither open nor un-flag.
     */
    public function getSubmissionById(int $id): ?Submission
    {
        return Submission::find()
            ->id($id)
            ->status(null)
            ->isIncomplete(null)
            ->isSpam(null)
            ->one();
    }

    /**
     * How many submissions a form has stored, whatever state they're in.
     *
     * Every one of them, complete or in progress, spam or not, holds answers
     * keyed by field handle - so this counts all of them, unlike a listing or
     * a digest, which only ever wants the completed, non-spam rows.
     */
    public function getSubmissionCount(int $formId): int
    {
        return (int)Submission::find()
            ->formId($formId)
            ->status(null)
            ->isIncomplete(null)
            ->isSpam(null)
            ->count();
    }

    /**
     * A blank submission for a form, with every field at its default value.
     */
    public function createSubmission(Form $form): Submission
    {
        $submission = new Submission();
        $submission->formId = $form->id;
        $submission->statusId = $form->defaultStatusId
            ?? Plugin::getInstance()->getStatuses()->getDefaultStatus()?->id;

        // Which site the visitor was on when they filled the form in. Set here
        // rather than in the controller because every route into a submission -
        // a posted form, the GraphQL mutation, a console replay - comes through
        // this method, and a submission that reached storage without an answer
        // can never be given one afterwards. Forms themselves stay global; see
        // internal/decisions/0019-submissions-record-their-site.md.
        $submission->submittedSiteId = Craft::$app->getSites()->getCurrentSite()->id;

        $values = [];

        foreach ($this->getValueFields($form) as $handle => $field) {
            $values[$handle] = $field->getDefaultValue();
        }

        $submission->setValues($values);

        return $submission;
    }

    /**
     * Maps raw posted values onto a submission.
     *
     * Only handles the form actually declares are read - a POST carrying extra
     * keys can't smuggle values into `formData`.
     *
     * @param array<string, mixed> $values
     */
    public function populateSubmission(Submission $submission, array $values): void
    {
        $form = $submission->getForm();

        if ($form === null) {
            return;
        }

        $submission->setValues($this->filterValues($this->getValueFields($form), $values));
    }

    /**
     * Keeps only the values whose handle belongs to the form, and that a
     * submitter is allowed to state outright.
     *
     * A File Upload field's value is a list of asset ids, and the only thing
     * that may put one there is {@see Uploads::handlePageUploads()}, after it
     * has moved a posted file into the field's own volume. Taking the ids from
     * the request instead would let an anonymous visitor name any asset in any
     * volume - private ones included - and have a notification attach it.
     * Leaving the key out (rather than blanking it) keeps the ids an earlier
     * page of a multi-page form already earned, since `setValues()` only
     * touches the handles it is given.
     *
     * @param array<string, FormField> $fields
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    public function filterValues(array $fields, array $values): array
    {
        $uploadHandles = array_filter($fields, static fn(FormField $field): bool => $field instanceof FileUpload);

        return array_diff_key(array_intersect_key($values, $fields), $uploadHandles);
    }

    /**
     * Validates raw values against a field map, returning error messages keyed
     * by handle.
     *
     * Fields absent from the input are still validated - that's what catches a
     * required field the submitter never touched (and what a hand-rolled POST
     * omitting the input would otherwise bypass).
     *
     * @param array<string, FormField> $fields
     * @param array<string, mixed> $values
     * @param array<string, array<string, mixed>> $subValues Posted sub-values, keyed by handle
     * @return array<string, array<int, string>>
     */
    public function validateValues(array $fields, array $values, array $subValues = []): array
    {
        $errors = [];

        foreach ($fields as $handle => $field) {
            $fieldErrors = $field->validateValue($values[$handle] ?? null, $subValues[$handle] ?? []);

            if ($fieldErrors !== []) {
                $errors[$handle] = $fieldErrors;
            }
        }

        return $errors;
    }

    /**
     * Validates a submission's values, attaching any errors to the element.
     *
     * Only fields the submitter was actually shown are validated. A required
     * field hidden by conditional logic must not be able to reject the
     * submission - it's the single most common way a conditional form becomes
     * impossible to submit, and the browser can't be trusted to have posted
     * the right subset.
     *
     * Pass `$pageIndex` to validate one page of a multi-page form, which is
     * what the Next button does.
     */
    public function validateSubmission(Submission $submission, ?int $pageIndex = null): bool
    {
        $form = $submission->getForm();

        if ($form === null) {
            $submission->addError('formId', Craft::t('formable', 'The form no longer exists.'));

            return false;
        }

        $values = $submission->getValues();
        $pages = Plugin::getInstance()->getTranslations()->localizePages($form);
        $fields = $this->getConditions()->getVisibleFields($form, $values, $pageIndex, $pages);
        $errors = $this->validateValues($fields, $values, $submission->getSubValues());

        foreach ($errors as $handle => $messages) {
            foreach ($messages as $message) {
                $submission->addFieldError($handle, $message);
            }
        }

        // Elements with titles require one, and the title is derived from the
        // values - so it has to exist before the element's own rules run, not
        // just before the save.
        $this->ensureTitle($submission);

        // The element's own rules (formId, statusId, title) still have to hold.
        $submission->validate(null, false);

        return !$submission->hasErrors();
    }

    /**
     * Whether a page's fields would validate, leaving a given set of handles
     * out of the check entirely.
     *
     * Doesn't attach errors to the submission - a plain yes/no a caller can
     * ask before doing something with a side effect of its own. Built for
     * uploads: a File Upload field's stored value doesn't exist until the
     * posted file has been moved into the volume, so
     * {@see \bytesof\formable\controllers\SubmissionsController::actionSubmit()}
     * excludes it here, asks whether the rest of the page is worth writing
     * the file for, and only then calls {@see Uploads::handlePageUploads()} -
     * which is what closes the file-upload half of the orphaned-asset finding
     * N9: nothing is written for a page that was going to fail anyway.
     *
     * @param array<int, string> $excludeHandles
     */
    public function pageValidatesExcluding(Submission $submission, int $pageIndex, array $excludeHandles): bool
    {
        $form = $submission->getForm();

        if ($form === null) {
            return false;
        }

        $values = $submission->getValues();
        $pages = Plugin::getInstance()->getTranslations()->localizePages($form);
        $fields = $this->getConditions()->getVisibleFields($form, $values, $pageIndex, $pages);

        foreach ($excludeHandles as $handle) {
            unset($fields[$handle]);
        }

        return $this->validateValues($fields, $values, $submission->getSubValues()) === [];
    }

    /**
     * Runs the full pipeline for an already-populated submission.
     *
     * Returns false when the submission was rejected; the reason is on the
     * submission's errors, or was announced through the error event when a
     * handler cancelled it. A spam submission is not a rejection in this sense
     * - see {@see handleSpam()} - so it returns true.
     *
     * The optional spam context carries the request's spam signals (honeypot,
     * captcha token, timing). A caller with none - the resume flow re-running a
     * submission it already accepted, a console submit - passes nothing and the
     * signal-dependent checks simply don't fire.
     */
    public function submitSubmission(Submission $submission, ?SpamContext $spam = null): bool
    {
        $form = $submission->getForm();

        if ($form === null) {
            $submission->addError('formId', Craft::t('formable', 'The form no longer exists.'));

            return false;
        }

        // Before anything reads the values: a field the submitter never saw
        // must not leave an answer behind. Without this a conditional field
        // filled in, then hidden by changing an earlier answer, would still be
        // stored, exported and sent in notifications.
        $this->applyConditions($submission);

        if (!$this->fireBeforeSubmit($submission)) {
            $this->fireError($submission, SubmissionErrorEvent::REASON_CANCELED);

            return false;
        }

        if (!$this->validateSubmission($submission)) {
            $this->fireError($submission, SubmissionErrorEvent::REASON_VALIDATION);

            return false;
        }

        // Spam is weighed only once the payload is otherwise valid - no point
        // scoring a request that would have been rejected anyway - and before
        // it is stored or allowed to notify anyone. A `beforeSubmit` handler
        // backed by an external spam service may already have marked it, and
        // that verdict has to stop notifications and integrations just as
        // ours does - so it skips the built-in checks rather than being
        // second-guessed by them.
        if (!$submission->isSpam) {
            $spamResult = $this->getSpam()->evaluate($form, $submission, $spam ?? SpamContext::empty());

            if ($spamResult->isSpam) {
                $submission->isSpam = true;
                $submission->spamReason = $spamResult->reason?->value;
            }
        }

        if ($submission->isSpam) {
            return $this->handleSpam($form, $submission);
        }

        // A form can be configured to notify without keeping a copy. The
        // element is still handed to `afterSubmit` so notifications and
        // integrations see the values - it just never reaches the database.
        if ($form->getFormSettings()->storeSubmissions && !$this->saveSubmission($submission)) {
            $this->fireError($submission, SubmissionErrorEvent::REASON_SAVE_FAILED);

            return false;
        }

        $this->fireAfterSubmit($submission);

        return true;
    }

    /**
     * Disposes of a submission marked as spam, by a built-in check or by a
     * `beforeSubmit` handler.
     *
     * Flagged spam is kept - stored with its reason so a human can find it in
     * the CP spam queue and rescue a false positive; rejected spam is dropped.
     * Neither path fires `afterSubmit`, so a bot triggers no notification or
     * integration either way.
     *
     * Returns true regardless: the submitter is shown the ordinary success
     * response, because telling a bot which check caught it only teaches it how
     * to slip past next time.
     */
    private function handleSpam(Form $form, Submission $submission): bool
    {
        // A single hook fires for either disposition, so logging or alerting on
        // spam doesn't have to infer it from the success path.
        $this->fireError($submission, SubmissionErrorEvent::REASON_SPAM);

        $settings = $form->getFormSettings();

        if ($settings->spamAction === FormSettings::SPAM_ACTION_FLAG && $settings->storeSubmissions) {
            $this->saveSubmission($submission);
        }

        return true;
    }

    /**
     * Saves a submission through the element service.
     *
     * Validation is not re-run here: {@see submitSubmission()} has already
     * validated the values, and the element's rules don't cover them.
     */
    public function saveSubmission(Submission $submission, bool $runValidation = false): bool
    {
        $isNew = $submission->id === null;

        $this->ensureTitle($submission);

        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_SUBMISSION)) {
            $event = new SubmissionEvent(['submission' => $submission, 'isNew' => $isNew]);
            $this->trigger(self::EVENT_BEFORE_SAVE_SUBMISSION, $event);

            if (!$event->isValid) {
                return false;
            }
        }

        try {
            if (!Craft::$app->getElements()->saveElement($submission, $runValidation)) {
                return false;
            }
        } catch (Throwable $e) {
            Craft::error(
                sprintf('Could not save Formable submission: %s', $e->getMessage()),
                __METHOD__,
            );

            $submission->addError('id', Craft::t('formable', 'The submission could not be saved.'));

            return false;
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_SUBMISSION)) {
            $this->trigger(self::EVENT_AFTER_SAVE_SUBMISSION, new SubmissionEvent([
                'submission' => $submission,
                'isNew' => $isNew,
            ]));
        }

        return true;
    }

    public function deleteSubmission(Submission $submission): bool
    {
        return Craft::$app->getElements()->deleteElement($submission);
    }

    /**
     * Rewrites the submission's rows in the relations index - one per element
     * an answer points at, from every field that points at one.
     *
     * Called from {@see Submission::afterSave()} rather than from this
     * service's own save: a submission is written through Craft's element
     * service, so the CP editor, a console command and a third-party module all
     * have to land in the index too, and only the element sees all of them.
     *
     * Delete-then-insert. A resave that dropped a selection has to drop its row,
     * and diffing a handful of rows would cost more than rewriting them.
     */
    public function saveRelations(Submission $submission): void
    {
        if ($submission->id === null) {
            return;
        }

        $fields = $submission->getFormFields();

        // No resolvable form means no field to ask what the answers point at.
        // Leave the existing rows alone rather than reading "nothing to index"
        // into what is really "nothing to index *with*".
        if ($fields === []) {
            return;
        }

        $rows = [];

        foreach ($fields as $handle => $field) {
            $sortOrder = 0;

            foreach ($field->getRelatedElementIds($submission->getValue($handle)) as $elementId) {
                $rows[] = [$submission->id, $elementId, $handle, $sortOrder++];
            }
        }

        Db::delete(Table::RELATIONS, ['submissionId' => $submission->id]);

        if ($rows === []) {
            return;
        }

        // `elementId` has a foreign key, so an id whose element is already gone
        // would abort the whole save. A stored value outliving its target is
        // exactly the case this index exists to make visible - it must not turn
        // a resave of an old submission into an integrity error.
        $live = $this->liveElementIds(array_column($rows, 1));

        $rows = array_values(array_filter(
            $rows,
            static fn(array $row): bool => isset($live[$row[1]]),
        ));

        if ($rows !== []) {
            Db::batchInsert(Table::RELATIONS, ['submissionId', 'elementId', 'fieldHandle', 'sortOrder'], $rows);
        }
    }

    /**
     * Which of the given element ids still have a row in Craft's elements
     * table, as an id-keyed lookup.
     *
     * Soft-deleted elements count: they are restorable, their row is still
     * there, and the foreign key is satisfied.
     *
     * @param array<int, int> $elementIds
     * @return array<int, true>
     */
    private function liveElementIds(array $elementIds): array
    {
        /** @var array<int, int|string> $found */
        $found = (new Query())
            ->select(['id'])
            ->from([CraftTable::ELEMENTS])
            ->where(['id' => array_values(array_unique($elementIds))])
            ->column();

        return array_fill_keys(array_map(intval(...), $found), true);
    }

    /**
     * Moves a submission to a status, saving through the element service so the
     * change fires the same events any other save would and lands in the index
     * immediately.
     */
    public function setStatus(Submission $submission, ?int $statusId): bool
    {
        $submission->statusId = $statusId;

        return $this->saveSubmission($submission);
    }

    /**
     * Flags a submission as spam, or clears the flag.
     *
     * Clearing also drops the stored reason - a reviewer who rescues a false
     * positive shouldn't leave "Blocked keyword" hanging off a submission
     * that's now been judged legitimate.
     */
    public function setSpam(Submission $submission, bool $spam): bool
    {
        $submission->isSpam = $spam;

        if (!$spam) {
            $submission->spamReason = null;
        }

        return $this->saveSubmission($submission);
    }

    /**
     * A submission's notes, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getNotes(int $submissionId): array
    {
        /** @var SubmissionNoteRecord[] $records */
        $records = SubmissionNoteRecord::find()
            ->where(['submissionId' => $submissionId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->all();

        return array_map(
            fn(SubmissionNoteRecord $record): array => [
                'id' => (int)$record->id,
                'note' => (string)$record->note,
                'author' => $this->userName($record->userId !== null ? (int)$record->userId : null),
                'dateCreated' => DateTimeHelper::toDateTime($record->dateCreated) ?: null,
            ],
            $records,
        );
    }

    /**
     * Records a note against a submission. An empty note is ignored rather than
     * stored as a blank row.
     */
    public function addNote(int $submissionId, string $note, ?int $userId): ?SubmissionNoteRecord
    {
        $note = trim($note);

        if ($note === '') {
            return null;
        }

        $record = new SubmissionNoteRecord();
        $record->submissionId = $submissionId;
        $record->userId = $userId;
        $record->note = $note;
        $record->save(false);

        return $record;
    }

    /**
     * Deletes a note only if it belongs to the given submission, so a caller
     * authorised against one submission can't reach a note on another.
     */
    public function deleteNoteById(int $id, int $submissionId): bool
    {
        $record = SubmissionNoteRecord::findOne(['id' => $id, 'submissionId' => $submissionId]);

        return $record === null || $record->delete() !== false;
    }

    /**
     * The field-level edit history of a submission, newest first - the audit
     * trail behind the Pro edit-history feature.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(int $submissionId): array
    {
        /** @var SubmissionHistoryRecord[] $records */
        $records = SubmissionHistoryRecord::find()
            ->where(['submissionId' => $submissionId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->all();

        return array_map(function(SubmissionHistoryRecord $record): array {
            $changes = $record->changes;

            if (is_string($changes)) {
                $changes = Json::decodeIfJson($changes);
            }

            return [
                'id' => (int)$record->id,
                'author' => $this->userName($record->userId !== null ? (int)$record->userId : null),
                'changes' => is_array($changes) ? $changes : [],
                'dateCreated' => DateTimeHelper::toDateTime($record->dateCreated) ?: null,
            ];
        }, $records);
    }

    /**
     * Records one edit's worth of changes, if there are any. A no-op for an
     * empty diff, so a save that touched nothing leaves no history row.
     *
     * @param array<string, array<string, string>> $changes
     */
    public function recordHistory(Submission $submission, array $changes, ?int $userId): void
    {
        if ($changes === [] || $submission->id === null) {
            return;
        }

        $record = new SubmissionHistoryRecord();
        $record->submissionId = $submission->id;
        $record->userId = $userId;
        // Assign the array directly - Craft's ActiveRecord JSON-encodes json()
        // columns on save (encoding a string here would double-encode).
        $record->changes = $changes;
        $record->save(false);
    }

    /**
     * Diffs two value sets against a submission's fields, returning the changed
     * fields as human-readable before/after strings keyed by handle.
     *
     * Comparison is on the string form each field presents, so a re-ordering
     * that stringifies identically isn't reported as a change.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     * @return array<string, array<string, string>>
     */
    public function diffValues(Submission $submission, array $before, array $after): array
    {
        $changes = [];

        foreach ($submission->getFormFields() as $handle => $field) {
            $old = $field->valueToString($before[$handle] ?? null);
            $new = $field->valueToString($after[$handle] ?? null);

            if ($old !== $new) {
                $changes[$handle] = [
                    'label' => $field->label !== '' ? $field->label : $handle,
                    'from' => $old,
                    'to' => $new,
                ];
            }
        }

        return $changes;
    }

    private function userName(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        return Craft::$app->getUsers()->getUserById($userId)?->getName();
    }

    /**
     * Clears the values of every field conditional logic has hidden.
     *
     * Idempotent, and safe to call on a partially-filled submission: fields on
     * pages the submitter hasn't reached yet are hidden by nothing, so they
     * keep whatever they hold.
     */
    public function applyConditions(Submission $submission): void
    {
        $form = $submission->getForm();

        if ($form === null) {
            return;
        }

        $visibility = $this->getConditions()->resolve($form, $submission->getValues())['fields'];

        foreach ($visibility as $handle => $visible) {
            if (!$visible) {
                $submission->setValue($handle, null);
            }
        }
    }

    /**
     * Stores a part-filled submission and returns its resume token.
     *
     * The row is written with `isIncomplete` set, which keeps it out of the CP
     * index and out of `craft.formable.submissions`, and given an expiry so an
     * abandoned form doesn't sit in the table indefinitely holding personal
     * data nobody ever chose to send.
     *
     * Reached only when the submitter has asked for the form to be kept - see
     * {@see \bytesof\formable\services\Progress}. Merely paging through a
     * multi-page form writes nothing.
     */
    public function saveIncomplete(Submission $submission, int $pageIndex): ?string
    {
        $form = $submission->getForm();

        if ($form === null) {
            return null;
        }

        $settings = $form->getFormSettings();

        $submission->isIncomplete = true;
        $submission->pageIndex = $pageIndex;
        $submission->resumeToken ??= self::generateResumeToken();
        $submission->dateExpired = DateTimeHelper::now()
            ->add(new DateInterval("P{$settings->saveAndResumeExpiryDays}D"));

        // Validation is per-page and has already run; re-running the whole
        // form's rules here would reject a submission for pages the submitter
        // hasn't reached yet.
        if (!$this->saveSubmission($submission)) {
            return null;
        }

        if ($this->hasEventHandlers(self::EVENT_AFTER_SAVE_INCOMPLETE)) {
            $this->trigger(self::EVENT_AFTER_SAVE_INCOMPLETE, new SubmissionEvent([
                'submission' => $submission,
                'isNew' => false,
            ]));
        }

        return $submission->resumeToken;
    }

    /**
     * Loads a resumable submission by its token.
     *
     * Scoped to the form, so a token for one form can't be replayed against
     * another, and expiry is checked here rather than left to the purge job -
     * a link that has outlived its window must stop working immediately, not
     * whenever the queue next runs.
     */
    public function getSubmissionByResumeToken(Form $form, string $token): ?Submission
    {
        if ($token === '' || $form->id === null) {
            return null;
        }

        $submission = Submission::find()
            ->formId($form->id)
            ->resumeToken($token)
            ->isIncomplete(true)
            ->status(null)
            ->one();

        if (!$submission instanceof Submission) {
            return null;
        }

        if ($submission->dateExpired !== null && $submission->dateExpired < DateTimeHelper::now()) {
            return null;
        }

        return $submission;
    }

    /**
     * Turns a resumed submission back into a normal one and runs the pipeline.
     *
     * The token is cleared on the way through: a resume link must not still
     * open a form that has already been submitted.
     */
    public function completeSubmission(Submission $submission, ?SpamContext $spam = null): bool
    {
        $submission->isIncomplete = false;
        $submission->resumeToken = null;
        $submission->dateExpired = null;

        return $this->submitSubmission($submission, $spam);
    }

    /**
     * Deletes up to `$limit` incomplete submissions whose resume window has
     * closed, returning the number removed.
     *
     * Bounded because the backlog isn't: {@see PurgeSubmissions} calls it again
     * until a pass comes back short ([[0112-submission-indexes-follow-the-measured-query-shapes]]).
     */
    public function purgeExpiredIncomplete(int $limit = self::PURGE_LIMIT): int
    {
        // Hard delete: a soft-deleted incomplete submission would still be
        // holding the data the expiry exists to get rid of.
        return $this->deleteInBatches(
            Submission::find()
                ->isIncomplete(true)
                ->status(null)
                ->andWhere(['not', ['formable_submissions.dateExpired' => null]])
                ->andWhere(['<', 'formable_submissions.dateExpired', Db::prepareDateForDb(new DateTime())]),
            $limit,
        );
    }

    /**
     * Deletes up to `$limit` completed submissions that have outlived their
     * form's retention window, returning the number removed.
     *
     * A Pro feature, and a hard delete on purpose: retention exists to get rid
     * of personal data, so a soft-deleted-but-still-present row would defeat it.
     */
    public function purgeForRetention(int $limit = self::PURGE_LIMIT): int
    {
        if (!Plugin::getInstance()->isPro()) {
            return 0;
        }

        $deleted = 0;

        foreach (Form::find()->status(null)->all() as $form) {
            $settings = $form->getFormSettings();

            if (!$settings->dataRetentionEnabled || $settings->dataRetentionDays < 1 || $form->id === null) {
                continue;
            }

            $cutoff = Db::prepareDateForDb(
                (new DateTime())->sub(new DateInterval("P{$settings->dataRetentionDays}D")),
            );

            $deleted += $this->deleteInBatches(
                Submission::find()
                    ->formId($form->id)
                    ->isIncomplete(false)
                    ->status(null)
                    ->andWhere(['<', 'formable_submissions.dateCreated', $cutoff]),
                $limit - $deleted,
            );

            if ($deleted >= $limit) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * Hard-deletes what `$query` matches, a hundred elements in memory at a
     * time, stopping at `$limit`.
     *
     * Re-queried from the top each round rather than paged by offset: every
     * round deletes what the last one read, so an offset would skip rows.
     *
     * @param SubmissionQuery<int, Submission> $query
     */
    private function deleteInBatches(SubmissionQuery $query, int $limit): int
    {
        $elements = Craft::$app->getElements();
        $deleted = 0;

        while ($deleted < $limit) {
            $batch = (clone $query)->limit(min(100, $limit - $deleted))->all();
            $progress = 0;

            foreach ($batch as $submission) {
                if ($elements->deleteElement($submission, true)) {
                    $progress++;
                }
            }

            $deleted += $progress;

            // A round that deleted nothing would read the same rows next time.
            if ($progress === 0) {
                break;
            }
        }

        return $deleted;
    }

    /**
     * A resume token.
     *
     * 32 hex characters from the CSPRNG - this is the whole credential on a
     * resume link, so it has to be unguessable rather than merely unique.
     */
    public static function generateResumeToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * The form's value-collecting fields, keyed by handle.
     *
     * @return array<string, FormField>
     */
    public function getValueFields(Form $form): array
    {
        return $this->getFields()->getValueFieldsFromLayout($form->getPages());
    }

    private function fireBeforeSubmit(Submission $submission): bool
    {
        if (!$this->hasEventHandlers(self::EVENT_BEFORE_SUBMIT)) {
            return true;
        }

        $event = new SubmissionEvent([
            'submission' => $submission,
            'isNew' => $submission->id === null,
        ]);

        $this->trigger(self::EVENT_BEFORE_SUBMIT, $event);

        return $event->isValid;
    }

    private function fireAfterSubmit(Submission $submission): void
    {
        if ($this->hasEventHandlers(self::EVENT_AFTER_SUBMIT)) {
            $this->trigger(self::EVENT_AFTER_SUBMIT, new SubmissionEvent([
                'submission' => $submission,
                'isNew' => true,
            ]));
        }
    }

    private function fireError(Submission $submission, string $reason): void
    {
        if ($this->hasEventHandlers(self::EVENT_SUBMISSION_ERROR)) {
            $this->trigger(self::EVENT_SUBMISSION_ERROR, new SubmissionErrorEvent([
                'submission' => $submission,
                'reason' => $reason,
            ]));
        }
    }

    /**
     * Gives the submission a title if it doesn't have one, leaving an
     * author-set title alone.
     */
    private function ensureTitle(Submission $submission): void
    {
        if ($submission->title === null || $submission->title === '') {
            $submission->title = $this->defaultTitle($submission);
        }
    }

    /**
     * Element titles are what the CP index lists, so a submission gets the
     * first couple of its own values, falling back to the form name and time
     * when every field is empty or presentational.
     */
    private function defaultTitle(Submission $submission): string
    {
        $summary = $submission->getSummary();

        if ($summary !== '') {
            return mb_substr($summary, 0, 255);
        }

        return Craft::t('formable', '{form} submission - {date}', [
            'form' => $submission->getForm()->title ?? Craft::t('formable', 'Form'),
            'date' => Craft::$app->getFormatter()->asDatetime(new \DateTime()),
        ]);
    }
}
