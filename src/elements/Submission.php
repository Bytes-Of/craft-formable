<?php

declare(strict_types=1);

namespace bytesof\formable\elements;

use bytesof\formable\base\FormField;
use bytesof\formable\elements\actions\SetSpamStatus;
use bytesof\formable\elements\actions\SetSubmissionStatus;
use bytesof\formable\elements\db\SubmissionQuery;
use bytesof\formable\elements\exporters\SensitiveSubmissionExport;
use bytesof\formable\elements\exporters\SubmissionExport;
use bytesof\formable\models\SpamReason;
use bytesof\formable\models\Status;
use bytesof\formable\Plugin;
use bytesof\formable\records\SubmissionRecord;
use Craft;
use craft\base\Element;
use craft\elements\actions\Delete;
use craft\elements\actions\Restore;
use craft\elements\User;
use craft\enums\Color;
use craft\helpers\Cp;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\models\Site;
use DateTime;

/**
 * Submission element. Field values are stored as JSON in the
 * formable_submissions.formData column, in each field's serialized form -
 * reads normalize back through the field, so callers always see the working
 * PHP representation regardless of whether the value came from a POST or a row.
 *
 * @api
 */
final class Submission extends Element
{
    /**
     * Prefix for per-field validation errors.
     *
     * Errors are namespaced so a field handle can never collide with an
     * element attribute - a field called `title` would otherwise overwrite the
     * element's own error.
     *
     * Not `field:`, which is Craft's own custom-field syntax:
     * {@see Element::addError()} strips that prefix back off, which would
     * silently undo the namespacing.
     *
     * @internal
     */
    public const FIELD_ERROR_PREFIX = 'formable:';

    /**
     * Marks a stored value as ciphertext.
     *
     * Only consulted for a field whose `encrypted` flag is on, so a plain value
     * that happens to start with it in a non-encrypted field is never mistaken
     * for one - and a value stored before the flag was turned on (no prefix)
     * still reads back as its plain self.
     */
    private const ENCRYPTED_PREFIX = 'formable:enc:';

    public ?int $formId = null;
    public ?int $statusId = null;
    public ?int $userId = null;

    /**
     * The site the submission was made from.
     *
     * Distinct from the element's own `siteId`, which is Craft's - a submission
     * is not localized, so that one is always the primary site and says nothing
     * about where the form was filled in. Null on a submission made with no
     * meaningful current site (a console replay), and on every row that
     * predates the column.
     */
    public ?int $submittedSiteId = null;

    public ?string $ipAddress = null;
    public ?string $userAgent = null;
    public bool $isIncomplete = false;
    public bool $isSpam = false;

    /**
     * Which spam check flagged this submission, as a stable code - one of
     * {@see \bytesof\formable\models\SpamReason}'s case values. Null on a clean
     * submission, and on one a reviewer has cleared by hand.
     */
    public ?string $spamReason = null;

    /**
     * Save-and-resume credential.
     *
     * The only thing standing between a resume link and someone else's
     * half-filled form, so it's generated with a CSPRNG and cleared the moment
     * the submission completes.
     *
     * @internal
     */
    public ?string $resumeToken = null;

    /**
     * The page the submitter is on, for an incomplete multi-page submission.
     *
     * @internal
     */
    public int $pageIndex = 0;

    /**
     * When an incomplete submission stops being resumable. Null on a completed
     * one - those are kept until the retention policy removes them.
     *
     * A `DateTime` like every other date on the element; Craft normalizes the
     * stored string on the way in via the type declaration.
     *
     * @internal
     */
    public ?DateTime $dateExpired = null;

    /** @var array<mixed>|null */
    private ?array $_formData = null;

    /**
     * Posted inputs that belong to a field's group but are not part of its
     * value - an email confirmation box, say.
     *
     * Request-scoped on purpose: they are validated against and then dropped,
     * never serialized into `formData`.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $_subValues = [];

    private ?Form $_form = null;

    /** @var array<string, FormField>|null */
    private ?array $_formFields = null;

    public static function displayName(): string
    {
        return Craft::t('formable', 'Submission');
    }

    public static function lowerDisplayName(): string
    {
        return Craft::t('formable', 'submission');
    }

    public static function pluralDisplayName(): string
    {
        return Craft::t('formable', 'Submissions');
    }

    public static function pluralLowerDisplayName(): string
    {
        return Craft::t('formable', 'submissions');
    }

    public static function hasTitles(): bool
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

    public static function hasStatuses(): bool
    {
        return true;
    }

    /**
     * The index's status menu and colored indicators are driven by the
     * per-install submission statuses - not Craft's enabled/disabled, which a
     * submission has no use for.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function statuses(): array
    {
        $statuses = [];

        foreach (Plugin::getInstance()->getStatuses()->getAllStatuses() as $status) {
            $statuses[$status->handle] = [
                'label' => $status->name,
                'color' => Color::tryFrom($status->color) ?? Color::Gray,
            ];
        }

        return $statuses;
    }

    /**
     * @return SubmissionQuery<int, self>
     */
    public static function find(): SubmissionQuery
    {
        return new SubmissionQuery(static::class);
    }

    /**
     * @return array<int|string, mixed>
     */
    protected static function defineSources(string $context): array
    {
        $sources = [
            [
                'key' => '*',
                'label' => Craft::t('formable', 'All submissions'),
                // No isSpam criterion needed: the query hides spam by default,
                // so this source shows only the legitimate submissions.
                'criteria' => [],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
            [
                'key' => 'spam',
                'label' => Craft::t('formable', 'Spam'),
                'criteria' => ['isSpam' => true],
                'defaultSort' => ['dateCreated', 'desc'],
            ],
        ];

        $forms = Form::find()->status(null)->orderBy(['title' => SORT_ASC])->all();

        if ($forms !== []) {
            $sources[] = ['heading' => Craft::t('formable', 'Forms')];
        }

        foreach ($forms as $form) {
            $sources[] = [
                'key' => "form:$form->id",
                'label' => (string)$form->title,
                'criteria' => ['formId' => $form->id],
                'defaultSort' => ['dateCreated', 'desc'],
                'data' => ['handle' => (string)$form->handle],
            ];
        }

        return $sources;
    }

    /**
     * @return array<int, mixed>
     */
    protected static function defineActions(string $source): array
    {
        $actions = [];

        foreach (Plugin::getInstance()->getStatuses()->getAllStatuses() as $status) {
            // One action per status reads better in the bulk menu than a single
            // action with a nested picker, and keeps each choice a single click.
            $actions[] = [
                'type' => SetSubmissionStatus::class,
                'statusId' => $status->id,
                'statusName' => $status->name,
            ];
        }

        // The spam queue offers a way back out (rescue a false positive); every
        // other source offers a way in (bin one the checks missed).
        $actions[] = [
            'type' => SetSpamStatus::class,
            'spam' => $source !== 'spam',
        ];

        $actions[] = [
            'type' => Delete::class,
            'confirmationMessage' => Craft::t('formable', 'Are you sure you want to delete the selected submissions?'),
            'successMessage' => Craft::t('formable', 'Submissions deleted.'),
        ];

        $actions[] = [
            'type' => Restore::class,
            'successMessage' => Craft::t('formable', 'Submissions restored.'),
            'partialSuccessMessage' => Craft::t('formable', 'Some submissions restored.'),
            'failMessage' => Craft::t('formable', 'Submissions not restored.'),
        ];

        return $actions;
    }

    /**
     * @return array<int, class-string<\craft\base\ElementExporterInterface>>
     */
    protected static function defineExporters(string $source): array
    {
        $exporters = [
            SubmissionExport::class,
        ];

        // Exporting encrypted answers in the clear is its own permission, so
        // the export that does it is only in the menu for someone who holds it.
        // Craft rejects an export request naming an exporter this method did
        // not offer, which is what makes the menu the gate.
        if (Craft::$app->getUser()->checkPermission('formable:exportSensitive')) {
            $exporters[] = SensitiveSubmissionExport::class;
        }

        return $exporters;
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
                'label' => Craft::t('app', 'Date Created'),
                // The submission row's own copy of the date, not the element's:
                // it sits in the same index as the form and spam filters, so a
                // page of a form's submissions reads 50 rows rather than
                // sorting all of them ([[0112-submission-indexes-follow-the-measured-query-shapes]]).
                'orderBy' => 'formable_submissions.dateCreated',
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
     * @return array<int, string>
     */
    protected static function defineSearchableAttributes(): array
    {
        // `values` is a pseudo-attribute - there is no such property. It exists
        // so the whole answer set is searchable, not just the two fields the
        // title happens to be built from. See {@see searchKeywords()}.
        return ['title', 'values'];
    }

    /**
     * @return array<string, array<string, string>>
     */
    protected static function defineTableAttributes(): array
    {
        $attributes = [
            'form' => ['label' => Craft::t('formable', 'Form')],
            'submissionStatus' => ['label' => Craft::t('formable', 'Status')],
            'user' => ['label' => Craft::t('formable', 'Submitted by')],
        ];

        // Offered only where it can differ. On a single-site install every
        // submission carries the same answer, so the column would be a row of
        // repeated text in the picker for no one's benefit.
        if (Craft::$app->getIsMultiSite()) {
            $attributes['submittedSite'] = ['label' => Craft::t('formable', 'Submitted from')];
        }

        return $attributes + [
            'ipAddress' => ['label' => Craft::t('formable', 'IP Address')],
            'spamReason' => ['label' => Craft::t('formable', 'Spam Reason')],
            'dateCreated' => ['label' => Craft::t('app', 'Date Created')],
            'dateUpdated' => ['label' => Craft::t('app', 'Date Updated')],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected static function defineDefaultTableAttributes(string $source): array
    {
        // The spam queue leads with why each submission was flagged; a per-form
        // source drops the (redundant) form column.
        return match ($source) {
            'spam' => ['form', 'spamReason', 'dateCreated'],
            '*' => ['form', 'submissionStatus', 'dateCreated'],
            default => ['submissionStatus', 'user', 'dateCreated'],
        };
    }

    protected function attributeHtml(string $attribute): string
    {
        switch ($attribute) {
            case 'form':
                $form = $this->getForm();

                return $form !== null
                    ? Html::a(Html::encode((string)$form->title), $form->getCpEditUrl() ?? '#')
                    : '';

            case 'submissionStatus':
                $status = $this->getStatusModel();

                if ($status === null) {
                    return '';
                }

                return Cp::statusLabelHtml([
                    'color' => Color::tryFrom($status->color) ?? Color::Gray,
                    'label' => $status->name,
                ]);

            case 'user':
                $user = $this->getUser();

                return $user !== null
                    ? Html::a(Html::encode($user->getName()), $user->getCpEditUrl() ?? '#')
                    : Html::tag('span', Craft::t('formable', 'Guest'), ['class' => 'light']);

            case 'submittedSite':
                $site = $this->getSubmittedSite();

                return $site !== null
                    ? Html::encode(Craft::t('site', $site->getName()))
                    : Html::tag('span', Craft::t('formable', 'Unknown'), ['class' => 'light']);

            case 'ipAddress':
                return $this->ipAddress !== null ? Html::encode($this->ipAddress) : '';

            case 'spamReason':
                return $this->isSpam
                    ? Html::encode(SpamReason::labelFor($this->spamReason))
                    : '';
        }

        return parent::attributeHtml($attribute);
    }

    /**
     * The status handle, so the index's colored indicator and status menu line
     * up with our own statuses rather than Craft's enabled/disabled.
     */
    public function getStatus(): ?string
    {
        return $this->getStatusModel()?->handle;
    }

    /** @internal */
    public function getStatusModel(): ?Status
    {
        return $this->statusId !== null
            ? Plugin::getInstance()->getStatuses()->getStatusById($this->statusId)
            : null;
    }

    /**
     * The logged-in user who submitted the form, if any.
     */
    public function getUser(): ?User
    {
        return $this->userId !== null ? Craft::$app->getUsers()->getUserById($this->userId) : null;
    }

    /**
     * The site the form was submitted from, if it is one that still exists.
     *
     * Not {@see Element::getSite()}, which answers the element's own site - the
     * primary one, always, since submissions aren't localized.
     */
    public function getSubmittedSite(): ?Site
    {
        return $this->submittedSiteId !== null
            ? Craft::$app->getSites()->getSiteById($this->submittedSiteId)
            : null;
    }

    protected function cpEditUrl(): ?string
    {
        return $this->id !== null
            ? UrlHelper::cpUrl("formable/submissions/$this->id")
            : null;
    }

    public function getPostEditUrl(): string
    {
        $form = $this->getForm();

        return UrlHelper::cpUrl($form !== null
            ? "formable/submissions/form/$form->handle"
            : 'formable/submissions');
    }

    public function getForm(): ?Form
    {
        if ($this->_form === null && $this->formId !== null) {
            $this->_form = Form::find()->id($this->formId)->status(null)->one();
        }

        return $this->_form;
    }

    /**
     * @return array<mixed>
     *
     * @internal
     */
    public function getFormData(): array
    {
        return $this->_formData ?? [];
    }

    /**
     * @param array<mixed>|string|null $formData
     *
     * @internal
     */
    public function setFormData(array|string|null $formData): void
    {
        if (is_string($formData)) {
            $formData = Json::decodeIfJson($formData);
        }

        $this->_formData = is_array($formData) ? $formData : null;
    }

    /**
     * @return array<string, array<string, mixed>>
     *
     * @internal
     */
    public function getSubValues(): array
    {
        return $this->_subValues;
    }

    /**
     * @param array<mixed> $subValues Raw `formableSubValues` post data
     *
     * @internal
     */
    public function setSubValues(array $subValues): void
    {
        $filtered = [];

        foreach ($subValues as $handle => $values) {
            if (is_string($handle) && is_array($values)) {
                $filtered[$handle] = $values;
            }
        }

        $this->_subValues = $filtered;
    }

    /**
     * The form's value-collecting fields, keyed by handle.
     *
     * Held on the submission once resolved: `getValue()`, `setValue()` and
     * `getFormField()` all come through here, so anything that walks a
     * submission's values would otherwise re-derive the map once per field.
     * Nothing caches until a form is actually found, for the same reason
     * {@see getForm()} doesn't - `formId` is a public property, and a
     * submission is routinely built before it is assigned.
     *
     * @return array<string, FormField>
     */
    public function getFormFields(): array
    {
        if ($this->_formFields !== null) {
            return $this->_formFields;
        }

        $form = $this->getForm();

        if ($form === null) {
            return [];
        }

        return $this->_formFields = Plugin::getInstance()->getFields()
            ->getFieldMap($form->getPages())
            ->valueFields();
    }

    public function getFormField(string $handle): ?FormField
    {
        return $this->getFormFields()[$handle] ?? null;
    }

    /**
     * A field's value in its working PHP representation.
     */
    public function getValue(string $handle): mixed
    {
        $field = $this->getFormField($handle);
        $raw = $this->getFormData()[$handle] ?? null;

        // Not `?->… ?? $raw` - a field that normalizes to null (an empty
        // optional field) would otherwise fall back to the raw value.
        return $field !== null ? $field->normalizeValue($this->decodeStored($field, $raw)) : $raw;
    }

    /**
     * Every value the form collects, normalized and keyed by handle.
     *
     * Fields the submission has no entry for come back as their default, so a
     * template can render a partially-filled form without null checks.
     *
     * @return array<string, mixed>
     */
    public function getValues(): array
    {
        $values = [];

        foreach ($this->getFormFields() as $handle => $field) {
            $values[$handle] = array_key_exists($handle, $this->getFormData())
                ? $field->normalizeValue($this->decodeStored($field, $this->getFormData()[$handle]))
                : $field->getDefaultValue();
        }

        return $values;
    }

    /**
     * @inheritdoc
     *
     * A submitted field's value, by handle - `{firstName}` and
     * `{{ object.firstName }}` in a success, error or closed message.
     * {@see \craft\base\Element}'s own default only recognises a real Craft
     * field-layout field, which a Formable field never is (internal/decisions/0003),
     * so a Formable field handle needs its own `isset()` the same way it needs
     * its own `__get()` below - without it, Twig's attribute resolution never
     * finds a property to allow and falls back to trying the handle as a bare
     * method call instead, which the sandbox correctly refuses.
     */
    public function __isset($name): bool
    {
        return $this->getFormField($name) !== null || parent::__isset($name);
    }

    /**
     * @inheritdoc
     */
    public function __get($name)
    {
        if ($this->getFormField($name) !== null) {
            return $this->getValue($name);
        }

        return parent::__get($name);
    }

    /**
     * @inheritdoc
     *
     * A submitted field's value, by handle - `{firstName}` and
     * `{{ object.firstName }}` in a success, error or closed message.
     * {@see \craft\base\Element}'s own default only recognises a real Craft
     * field-layout field, which a Formable field never is (internal/decisions/0003).
     * See internal/decisions/0099.
     *
     * @internal
     */
    public function propertyAllowedInSandbox(string $property): bool
    {
        return $this->getFormField($property) !== null || parent::propertyAllowedInSandbox($property);
    }

    /**
     * @inheritdoc
     *
     * `getValue()`/`getValues()` reach the same submitted answers a
     * notification's `values` variable already exposes unsandboxed - see
     * {@see \bytesof\formable\services\Notifications::variables()} - just through
     * the object directly, which a message rendered against the submission has
     * no other variable for. See internal/decisions/0099.
     *
     * @internal
     */
    public function methodAllowedInSandbox(string $method): bool
    {
        return in_array(strtolower($method), ['getvalue', 'getvalues'], true) || parent::methodAllowedInSandbox($method);
    }

    /**
     * Sets a field's value from any source (POST, GraphQL, a console command).
     *
     * The value is normalized and then serialized, so what lands in `formData`
     * is always storage-shaped.
     */
    public function setValue(string $handle, mixed $value): void
    {
        $field = $this->getFormField($handle);

        if ($field === null) {
            return;
        }

        $formData = $this->getFormData();
        $formData[$handle] = $this->encodeStored($field, $field->serializeValue($field->normalizeValue($value)));
        $this->_formData = $formData;
    }

    /**
     * Ciphers a serialized value for storage, when the field asks for it.
     *
     * A null (empty) value is left alone - there's nothing to protect, and
     * storing a cipher of "nothing" would only obscure that the field is empty.
     */
    private function encodeStored(FormField $field, mixed $serialized): mixed
    {
        if (!$field->encrypted || $serialized === null) {
            return $serialized;
        }

        $cipher = Craft::$app->getSecurity()->encryptByKey(Json::encode($serialized));

        return self::ENCRYPTED_PREFIX . base64_encode($cipher);
    }

    /**
     * Deciphers a stored value, if it's one this field enciphered.
     *
     * A decryption failure (the security key changed, corrupt data) yields null
     * rather than throwing - a single unreadable field mustn't take out the
     * whole submission.
     */
    private function decodeStored(FormField $field, mixed $raw): mixed
    {
        if (!$field->encrypted || !is_string($raw) || !str_starts_with($raw, self::ENCRYPTED_PREFIX)) {
            return $raw;
        }

        $cipher = base64_decode(substr($raw, strlen(self::ENCRYPTED_PREFIX)), true);

        if ($cipher === false) {
            return null;
        }

        try {
            $plain = Craft::$app->getSecurity()->decryptByKey($cipher);
        } catch (\Throwable) {
            return null;
        }

        return Json::decodeIfJson($plain);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function setValues(array $values): void
    {
        foreach ($values as $handle => $value) {
            if (is_string($handle)) {
                $this->setValue($handle, $value);
            }
        }
    }

    public function addFieldError(string $handle, string $message): void
    {
        $this->addError(self::FIELD_ERROR_PREFIX . $handle, $message);
    }

    /**
     * @return array<int, string>
     */
    public function getFieldErrors(string $handle): array
    {
        return array_values($this->getErrors(self::FIELD_ERROR_PREFIX . $handle));
    }

    /**
     * Every field's errors, keyed by handle - the shape the AJAX response and
     * the re-rendered template both consume.
     *
     * @return array<string, array<int, string>>
     */
    public function getAllFieldErrors(): array
    {
        $errors = [];
        $prefixLength = strlen(self::FIELD_ERROR_PREFIX);

        foreach ($this->getErrors() as $attribute => $messages) {
            if (str_starts_with($attribute, self::FIELD_ERROR_PREFIX)) {
                $errors[substr($attribute, $prefixLength)] = array_values($messages);
            }
        }

        return $errors;
    }

    /**
     * Errors that aren't attached to a field - a missing form, a failed save.
     *
     * Kept separate from {@see getAllFieldErrors()} so a response can report
     * "something is wrong with this form" distinctly from "this input is
     * wrong", and so the internal error prefix never leaves the plugin.
     *
     * @return array<string, array<int, string>>
     */
    public function getNonFieldErrors(): array
    {
        $errors = [];

        foreach ($this->getErrors() as $attribute => $messages) {
            if (!str_starts_with($attribute, self::FIELD_ERROR_PREFIX)) {
                $errors[$attribute] = array_values($messages);
            }
        }

        return $errors;
    }

    /**
     * A human-readable summary of the submission, used as the element title.
     */
    public function getSummary(): string
    {
        $parts = [];

        foreach ($this->getFormFields() as $handle => $field) {
            // An encrypted field's whole point is to stay out of the clear -
            // the title is stored plaintext and shown in the index, so it must
            // never be built from one.
            if ($field->encrypted) {
                continue;
            }

            $string = $field->valueToString($this->getValue($handle));

            if ($string !== '') {
                $parts[] = $string;
            }

            if (count($parts) === 2) {
                break;
            }
        }

        return implode(' - ', $parts);
    }

    /**
     * Feeds the `values` pseudo-attribute declared in
     * {@see defineSearchableAttributes()} with every answer the submission
     * holds, so "find the person from acme.test" works from the index search
     * box regardless of which field the address landed in.
     *
     * Encrypted fields are excluded for the same reason {@see getSummary()}
     * excludes them: the search index is a plaintext table, and putting a
     * decrypted value in it would defeat the field's only purpose.
     */
    protected function searchKeywords(string $attribute): string
    {
        if ($attribute !== 'values') {
            return parent::searchKeywords($attribute);
        }

        $parts = [];

        foreach ($this->getFormFields() as $handle => $field) {
            if ($field->encrypted) {
                continue;
            }

            $string = $field->valueToString($this->getValue($handle));

            if ($string !== '') {
                $parts[] = $string;
            }
        }

        return implode(' ', $parts);
    }

    public function canView(User $user): bool
    {
        return $user->can('formable:viewSubmissions');
    }

    public function canSave(User $user): bool
    {
        return $user->can('formable:saveSubmissions');
    }

    public function canDelete(User $user): bool
    {
        return $user->can('formable:deleteSubmissions');
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['formId'], 'required'];
        $rules[] = [['formId', 'statusId'], 'number', 'integerOnly' => true];

        return $rules;
    }

    public function afterSave(bool $isNew): void
    {
        if (!$this->propagating) {
            $record = null;

            if (!$isNew) {
                $record = SubmissionRecord::findOne($this->id);
            }

            if ($record === null) {
                $record = new SubmissionRecord();
                $record->id = $this->id;
            }

            $record->formId = $this->formId;
            $record->statusId = $this->statusId;
            $record->userId = $this->userId;
            // Assign the array directly - Craft's ActiveRecord JSON-encodes
            // json() columns on save (encoding a string here double-encodes).
            $record->formData = $this->getFormData();
            $record->submittedSiteId = $this->submittedSiteId;
            $record->ipAddress = $this->ipAddress;
            $record->userAgent = $this->userAgent;
            $record->isIncomplete = $this->isIncomplete;
            $record->isSpam = $this->isSpam;
            $record->spamReason = $this->spamReason;
            $record->resumeToken = $this->resumeToken;
            $record->pageIndex = $this->pageIndex;
            $record->dateExpired = Db::prepareDateForDb($this->dateExpired);

            // Mirrors the element's date, which every date sort and window
            // reads from this column - left to the record, it would stamp
            // "now" over an imported submission's real date.
            if ($record->getIsNewRecord() && $this->dateCreated !== null) {
                $record->dateCreated = Db::prepareDateForDb($this->dateCreated);
            }

            $record->save(false);

            // The answers are stored as ids inside `formData`, which no query
            // can reach across. The relations index is how "which submissions
            // reference this entry?" gets answered, and it is rewritten here so
            // every path that saves a submission maintains it.
            Plugin::getInstance()->getSubmissions()->saveRelations($this);
            Plugin::getInstance()->getUploads()->claimUploads($this);
        }

        parent::afterSave($isNew);
    }

    /**
     * A permanent delete takes the submission's own uploads with it straight
     * away, so an erasure request is complete when the CP says so. A trip to
     * the trash leaves them; garbage collection catches a trashed submission
     * that is later purged.
     */
    public function afterDelete(): void
    {
        if ($this->hardDelete && $this->id !== null) {
            Plugin::getInstance()->getUploads()->deleteOrphanedUploads($this->id);
        }

        parent::afterDelete();
    }
}
