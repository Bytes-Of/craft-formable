<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\Plugin;
use Craft;
use craft\helpers\Json;
use yii\base\Component;

/**
 * Where a multi-page form's answers live between one page and the next.
 *
 * There are two places they can live, and which one is in play is decided by
 * what the submitter has actually asked for rather than by how the form is
 * built:
 *
 * - **The session, by default.** Paging through a form is navigation, not
 *   submission. Walking to page two is not a decision to hand over personal
 *   data - the submitter hasn't pressed anything called Submit yet - so the
 *   answers so far are held in their own session and nothing is written to the
 *   database. An abandoned form leaves nothing behind but a session key that
 *   expires with the session.
 *
 * - **An incomplete submission row, once the submitter has parked the form.**
 *   Pressing "Save and finish later" (Pro, opt-in per form) or arriving back
 *   through a resume link is an explicit request to have the part-filled form
 *   *kept* - which is the only thing that justifies storing it. From then on
 *   the flow stays record-backed, so every further page keeps the resume link
 *   current. A form whose answers have simply outgrown the session - a large
 *   signature, a long table - is moved here too, since the record store is
 *   built to hold values that size and the session is not.
 *
 * Either store is keyed to one pass through the form: the form shell plants a
 * per-flow id ({@see newFlowId()}) that rides every step, so two tabs open on
 * the same form fill in two separate submissions rather than one interleaved
 * one. A request with no flow id (a hand-rolled post, an older cached page)
 * falls back to a slot keyed on the form handle alone.
 *
 * The rule is one line - a flow already backed by a row keeps using it,
 * everything else uses the session - and it is what stops an anonymous
 * `formableStep=next` loop from creating unbounded rows: reaching the record
 * store at all requires going through the save action, which is Pro-gated,
 * needs an email address, and is weighed against the spam checks first.
 *
 * See internal/decisions/0016-page-progress-is-session-state.md.
 *
 * @internal
 */
final class Progress extends Component
{
    /**
     * Session key prefix for a form's in-flight answers.
     *
     * Suffixed with the form handle so two different forms on one page don't
     * share a slot, and then with a per-flow id (see {@see newFlowId()}) when
     * the request carries one, so two tabs open on the *same* form don't
     * interleave their answers into one submission either.
     */
    private const SESSION_PREFIX = 'formable:progress:';

    /**
     * The most an encoded progress payload may take up before the flow is moved
     * to the record store instead of being written to the session.
     *
     * Well inside the 1 MB per-item ceiling that Redis and Memcached - the
     * session backends a production Craft install typically uses - impose by
     * default. Over that ceiling those backends drop the write with no
     * exception, so the submitter's earlier answers vanish silently on Next;
     * staying under it lets an oversized flow fall back cleanly instead.
     */
    public const MAX_SESSION_BYTES = 256_000;

    private ?Submissions $_submissions = null;

    /**
     * Flow keys already logged as having outgrown the session, so a form that
     * pages several times past the budget records the fallback once rather than
     * on every step.
     *
     * @var array<string, true>
     */
    private array $_loggedOversize = [];

    /**
     * Overrides the submission service, so the store can be exercised without
     * a booted plugin. Mirrors the seams on the other services.
     */
    public function setSubmissions(Submissions $submissions): void
    {
        $this->_submissions = $submissions;
    }

    public function getSubmissions(): Submissions
    {
        return $this->_submissions ??= Plugin::getInstance()->getSubmissions();
    }

    /**
     * The form's in-flight submission for this session, or null when there
     * isn't one.
     *
     * Returns an element either way: a record-backed flow gets the stored row,
     * a session-backed one gets a fresh element carrying the stored answers.
     * Nothing downstream has to know which - only that it holds what has been
     * answered so far.
     */
    public function load(Form $form, ?string $flowId = null): ?Submission
    {
        $session = Craft::$app->getSession();

        // Asked on every render of every form, including the first anonymous
        // page view - and reading a session key opens one. A visitor who has
        // not started a form has no session to look in, and must not be given
        // one (and a session cookie) just to be told so.
        if (!$session->getIsActive() && !$session->getHasSessionId()) {
            return null;
        }

        $stored = $session->get($this->key($form, $flowId));

        if (is_int($stored)) {
            return $this->loadRecord($form, $stored);
        }

        if (is_array($stored)) {
            return $this->loadSession($form, $stored);
        }

        return null;
    }

    /**
     * Stores the answers so far, at the page the submitter is moving to.
     *
     * A flow that is already record-backed updates its row; a flow whose answers
     * have outgrown the session ({@see MAX_SESSION_BYTES}) is moved to a row and
     * stays there; every other one writes to the session. Returns false only
     * when a record-backed save failed - a session write cannot fail in a way
     * the caller can act on.
     */
    public function persist(Form $form, Submission $submission, int $pageIndex, ?string $flowId = null): bool
    {
        if ($this->isRecordBacked($submission) || $this->outgrewSession($form, $submission, $flowId)) {
            if ($this->getSubmissions()->saveIncomplete($submission, $pageIndex) === null) {
                return false;
            }

            $this->attach($form, $submission, $flowId);

            return true;
        }

        $submission->pageIndex = $pageIndex;

        Craft::$app->getSession()->set($this->key($form, $flowId), [
            // Stored as `formData` rather than as normalized values: that is
            // the shape the element already keeps, so an encrypted field stays
            // ciphered in the session exactly as it would in the database.
            'formData' => $submission->getFormData(),
            'pageIndex' => $pageIndex,
        ]);

        return true;
    }

    /**
     * Points this session at a stored incomplete submission - what a resume
     * link does on the way in, and what pressing Save does on the way out.
     *
     * From here on the flow is record-backed, so the row the submitter was
     * promised keeps up with the pages they fill in afterwards.
     */
    public function attach(Form $form, Submission $submission, ?string $flowId = null): void
    {
        if ($submission->id !== null) {
            Craft::$app->getSession()->set($this->key($form, $flowId), $submission->id);
        }
    }

    /**
     * Drops the form's progress, whichever store held it.
     *
     * The row itself is left alone: by the time this is called it is either the
     * completed submission or an abandoned one the expiry will reap.
     */
    public function forget(Form $form, ?string $flowId = null): void
    {
        Craft::$app->getSession()->remove($this->key($form, $flowId));
    }

    /**
     * A fresh identifier for one pass through a form.
     *
     * Planted in the form shell at render and posted back on every step, so two
     * tabs open on the same form key their in-flight answers to different
     * session slots instead of interleaving into one submission.
     */
    public function newFlowId(): string
    {
        return Craft::$app->getSecurity()->generateRandomString(16);
    }

    /**
     * Narrows a value posted as a flow id to one that is safe to use as a
     * session key, or null for anything that isn't the shape
     * {@see newFlowId()} produces.
     */
    public static function normalizeFlowId(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9_\-]{1,64}$/', $value) === 1
            ? $value
            : null;
    }

    /**
     * Whether a submission's progress belongs in the database.
     *
     * True only of an element that is already a stored incomplete row - saved
     * for later, or resumed from a link. A fresh in-memory submission is not,
     * and never becomes one by paging.
     */
    public function isRecordBacked(Submission $submission): bool
    {
        return $submission->id !== null && $submission->isIncomplete;
    }

    /**
     * Whether this flow's answers no longer fit the session and belong in the
     * record store from here on.
     *
     * Only a form that carries a field capable of holding a large value - a
     * signature, a table ({@see \bytesof\formable\base\FormField::isBulky()}) - is
     * measured at all; every other form's payload is nowhere near the ceiling
     * and pays nothing. Over the budget, the flow falls back to the same
     * record-backed store a parked form uses, and the fallback is logged once so
     * it is diagnosable rather than silent.
     */
    private function outgrewSession(Form $form, Submission $submission, ?string $flowId): bool
    {
        if (!$this->hasBulkyField($form)) {
            return false;
        }

        $bytes = strlen(Json::encode($submission->getFormData()));

        if ($bytes <= self::MAX_SESSION_BYTES) {
            return false;
        }

        $key = $this->key($form, $flowId);

        if (!isset($this->_loggedOversize[$key])) {
            $this->_loggedOversize[$key] = true;

            Craft::warning(sprintf(
                'Form "%s" in-flight answers reached %d bytes; moving this flow to the record store for the rest of the form.',
                $form->handle,
                $bytes,
            ), 'formable');
        }

        return true;
    }

    private function hasBulkyField(Form $form): bool
    {
        foreach ($this->getSubmissions()->getValueFields($form) as $field) {
            if ($field->isBulky()) {
                return true;
            }
        }

        return false;
    }

    private function loadRecord(Form $form, int $id): ?Submission
    {
        $submission = $this->getSubmissions()->getSubmissionById($id);

        // The stored row has to still be an incomplete one for this form: a
        // completed or deleted submission means the session is stale.
        if (
            $submission === null
            || !$submission->isIncomplete
            || $submission->formId !== $form->id
        ) {
            return null;
        }

        return $submission;
    }

    /**
     * @param array<mixed> $stored
     */
    private function loadSession(Form $form, array $stored): ?Submission
    {
        $formData = $stored['formData'] ?? null;

        if (!is_array($formData)) {
            return null;
        }

        $submission = $this->getSubmissions()->createSubmission($form);

        // Straight onto `formData`, not through `setValues()`: the session
        // holds storage-shaped values, and re-normalizing them would decipher
        // and re-encipher every encrypted field for nothing. Merged over the
        // defaults so a field added to the form mid-session still has one, and
        // a handle the form no longer declares is simply never read.
        $submission->setFormData(array_merge($submission->getFormData(), $formData));
        $submission->pageIndex = is_numeric($stored['pageIndex'] ?? null) ? (int)$stored['pageIndex'] : 0;

        return $submission;
    }

    private function key(Form $form, ?string $flowId = null): string
    {
        $key = self::SESSION_PREFIX . $form->handle;

        return $flowId !== null && $flowId !== '' ? "$key:$flowId" : $key;
    }
}
