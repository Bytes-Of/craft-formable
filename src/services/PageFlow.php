<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\models\FlowRequest;
use bytesof\formable\models\FlowResult;
use bytesof\formable\models\PageFlowStep;
use bytesof\formable\Plugin;
use Craft;
use yii\base\Component;

/**
 * The rules for walking a submitter through a form, with no transport attached.
 *
 * Two things live here. The first is the shape of the walk: which pages a
 * submission will actually be taken through once conditional logic has had its
 * say, what comes next, what came before, and where the submitter is in the
 * sequence. The second is the walk itself - the four moves a submitter can make
 * (next, back, submit, save) and what each one is allowed to do.
 *
 * None of it knows what a request is. A move arrives as a {@see FlowRequest}
 * and leaves as a {@see FlowResult}, which is what lets the same rules serve a
 * plain form post, the AJAX path and a headless caller without any of them
 * re-deciding when a page is valid, when a review step appears, or when
 * something may be written on an anonymous submitter's behalf.
 *
 * What is *not* here: how answers are stored between pages (see {@see Progress}),
 * how a submission is validated or completed (see {@see Submissions}), and how
 * anything is rendered or reported (see {@see Rendering}, and each caller).
 *
 * @internal
 */
final class PageFlow extends Component
{
    private ?Conditions $_conditions = null;

    private ?Submissions $_submissions = null;

    private ?Progress $_progress = null;

    private ?Spam $_spam = null;

    private ?SubmissionGate $_gate = null;

    /**
     * Service overrides, so the flow can be exercised without a booted plugin.
     * Mirrors the seams on the other services.
     */
    public function setConditions(Conditions $conditions): void
    {
        $this->_conditions = $conditions;
    }

    public function getConditions(): Conditions
    {
        return $this->_conditions ??= Plugin::getInstance()->getConditions();
    }

    public function setSubmissions(Submissions $submissions): void
    {
        $this->_submissions = $submissions;
    }

    public function getSubmissions(): Submissions
    {
        return $this->_submissions ??= Plugin::getInstance()->getSubmissions();
    }

    public function setProgress(Progress $progress): void
    {
        $this->_progress = $progress;
    }

    public function getProgress(): Progress
    {
        return $this->_progress ??= Plugin::getInstance()->getProgress();
    }

    public function setSpamService(Spam $spam): void
    {
        $this->_spam = $spam;
    }

    public function getSpam(): Spam
    {
        return $this->_spam ??= Plugin::getInstance()->getSpam();
    }

    public function setGate(SubmissionGate $gate): void
    {
        $this->_gate = $gate;
    }

    public function getGate(): SubmissionGate
    {
        return $this->_gate ??= Plugin::getInstance()->getSubmissionGate();
    }

    /**
     * The indexes of the pages this submission will actually be walked
     * through, in order, with conditionally-hidden pages left out.
     *
     * @return array<int, int>
     */
    public function getPageSequence(Submission $submission): array
    {
        $form = $submission->getForm();

        if ($form === null) {
            return [0];
        }

        $sequence = $this->getConditions()->getVisiblePageIndexes($form, $submission->getValues());

        // A form whose every page is conditioned out still has to render
        // something, or the submitter gets a blank page with a Submit button.
        return $sequence !== [] ? $sequence : [0];
    }

    /**
     * The page after the current one, or null when the current page is the
     * last - which is what turns a Next into a submit.
     */
    public function getNextPageIndex(Submission $submission, int $currentPage): ?int
    {
        foreach ($this->getPageSequence($submission) as $index) {
            if ($index > $currentPage) {
                return $index;
            }
        }

        return null;
    }

    public function getPreviousPageIndex(Submission $submission, int $currentPage): ?int
    {
        $previous = null;

        foreach ($this->getPageSequence($submission) as $index) {
            if ($index >= $currentPage) {
                break;
            }

            $previous = $index;
        }

        return $previous;
    }

    public function isLastPage(Submission $submission, int $pageIndex): bool
    {
        return $this->getNextPageIndex($submission, $pageIndex) === null;
    }

    /**
     * Where a page sits in the sequence the submitter sees, 1-based.
     *
     * Progress is reported over the *visible* pages, so a form that skips two
     * of its five pages reads “2 of 3” rather than jumping from 2 to 5.
     *
     * @return array{step: int, total: int}
     */
    public function getProgressPosition(Submission $submission, int $pageIndex): array
    {
        $sequence = $this->getPageSequence($submission);
        $position = array_search($pageIndex, $sequence, true);

        return [
            'step' => is_int($position) ? $position + 1 : 1,
            'total' => count($sequence),
        ];
    }

    /**
     * The page a move concerns, clamped into range.
     *
     * A posted index is trusted only as a hint: it names which page's fields to
     * validate, but the fields themselves are always looked up from the form,
     * so a forged index can at most validate the wrong (still real) page. With
     * no hint at all the submission's own page stands.
     */
    public function resolvePage(Form $form, Submission $submission, ?int $posted): int
    {
        return $this->clampPage($form, $posted ?? $submission->pageIndex);
    }

    /**
     * A review-step "edit" link's target, restricted to a page that exists.
     *
     * Bounded to the form's real pages so a forged index can't send the
     * submitter somewhere there is nothing to render.
     */
    public function resolveGoto(Form $form, ?int $goto): ?int
    {
        if ($goto === null) {
            return null;
        }

        return $goto >= 0 && $goto < count($form->getPages()) ? $goto : null;
    }

    private function clampPage(Form $form, int $page): int
    {
        return max(0, min($page, max(0, count($form->getPages()) - 1)));
    }

    /**
     * The submission a move builds on.
     *
     * A multi-page form's answers so far are carried by {@see Progress}, so a
     * move that only names its own page's fields still builds on every page
     * before it. Anything with no progress to find - a single-page form, or the
     * first page of a multi-page one - starts fresh.
     */
    public function begin(Form $form, ?string $flowId = null): Submission
    {
        return $this->getProgress()->load($form, $flowId)
            ?? $this->getSubmissions()->createSubmission($form);
    }

    /**
     * Writes a move's answers onto the submission, along with the metadata the
     * form has asked to collect.
     *
     * Kept separate from {@see handle()} so a caller that has files to attach
     * can do so in between - an upload has to land on the submission after the
     * posted values, or the values would blank it out again.
     */
    public function populate(Submission $submission, FlowRequest $request): void
    {
        $settings = $request->form->getFormSettings();

        $this->getSubmissions()->populateSubmission($submission, $request->values);

        // Inputs that sit inside a field's group without being part of its
        // value (an email confirmation box). They arrive under their own key so
        // they can't flatten the value they belong to, and they're carried on
        // the element only for as long as validation needs them.
        $submission->setSubValues($request->subValues);

        // Tie the submission to the visitor if they're logged in, so it can be
        // handed back to them through `craft.formable.submissions`. Left null
        // for an anonymous submit - a guest owns nothing to come back to.
        $submission->userId ??= $request->userId;

        // Both opt-in, per the form's own privacy settings - Craft's own
        // `storeUserIps` is respected on top, since a site-wide "don't store
        // IPs" has to beat a per-form "do".
        if ($settings->collectIp && Craft::$app->getConfig()->getGeneral()->storeUserIps) {
            $submission->ipAddress = $request->ipAddress;
        }

        if ($settings->collectUserAgent) {
            $submission->userAgent = $request->userAgent !== null
                ? mb_substr($request->userAgent, 0, 512)
                : null;
        }
    }

    /**
     * Makes the move.
     *
     * A "goto" beats the step name: the review step's edit links name a page to
     * jump to, which is a back-style move to a specific page rather than to the
     * previous one.
     */
    public function handle(Submission $submission, FlowRequest $request): FlowResult
    {
        $page = $this->resolvePage($request->form, $submission, $request->page);
        $goto = $this->resolveGoto($request->form, $request->goto);

        if ($goto !== null) {
            return $this->back($submission, $request, $page, $goto);
        }

        return match ($request->step) {
            PageFlowStep::BACK => $this->back($submission, $request, $page),
            PageFlowStep::SAVE => $this->save($submission, $request, $page),
            PageFlowStep::NEXT => $this->next($submission, $request, $page),
            default => $this->submit($submission, $request, $page),
        };
    }

    private function next(Submission $submission, FlowRequest $request, int $page): FlowResult
    {
        // Only the page in front of the submitter is validated - a later page's
        // required field can't block them from advancing to it.
        if (!$this->getSubmissions()->validateSubmission($submission, $page)) {
            return FlowResult::invalid($submission, $page);
        }

        $nextPage = $this->getNextPageIndex($submission, $page);

        // "Next" off the last page is a submit - the button is only labelled
        // differently - unless a review step stands between them.
        if ($nextPage === null && !$this->wantsReview($submission, $request, $page)) {
            return $this->complete($submission, $request);
        }

        $review = $nextPage === null;
        $targetPage = $nextPage ?? $page;
        $this->persist($submission, $request, $targetPage);

        return FlowResult::advanced($submission, $targetPage, $review);
    }

    private function back(Submission $submission, FlowRequest $request, int $page, ?int $goto = null): FlowResult
    {
        $targetPage = $goto ?? $this->getPreviousPageIndex($submission, $page) ?? $page;

        // Progress is kept on the way back, but not validated: a submitter
        // stepping back to fix an earlier answer shouldn't be stopped by the
        // page they're leaving being incomplete.
        $this->persist($submission, $request, $targetPage);

        return FlowResult::advanced($submission, $targetPage, false);
    }

    private function submit(Submission $submission, FlowRequest $request, int $page): FlowResult
    {
        // The final submit validates every field the submitter was shown,
        // across every page - not just the one the button lives on.
        if (!$this->getSubmissions()->validateSubmission($submission)) {
            return FlowResult::invalid($submission, $page);
        }

        return $this->complete($submission, $request);
    }

    private function complete(Submission $submission, FlowRequest $request): FlowResult
    {
        $form = $request->form;

        // This is the one place both `submit` and a `next` off the last page
        // end up - it stores a row, sends every notification and fires every
        // integration, so it is rate-limited the same way save() already is
        // ([[0030-anonymous-form-actions-are-rate-limited]]), against its own
        // budget rather than save's ([[0084-submit-and-uploads-get-their-own-limit]]).
        // The GraphQL mutation reaches `Submissions::submitSubmission()`
        // directly rather than through this method, so the check lives on
        // {@see SubmissionGate} and both callers ask it the same way
        // ([[0088]]) - bucketed under `submit` regardless of which step or
        // entry point reached it, so a caller can't double their effective
        // allowance by splitting requests across them. A throttled request is
        // answered exactly as a completed one, for the same reason save()'s
        // is - only the gate's own log line says otherwise.
        if ($this->getGate()->isSubmitLimited($form, $request->ipAddress)) {
            return FlowResult::completed($submission);
        }

        // The spam evidence is weighed in full only at the moment of the real
        // submit - a multi-page form's earlier `next` moves carry none.
        if (!$this->getSubmissions()->completeSubmission($submission, $request->spam)) {
            return FlowResult::invalid($submission, 0);
        }

        // The working submission is now the completed one, so the session's
        // hold on it has done its job.
        $this->getProgress()->forget($form, $request->flowId);

        return FlowResult::completed($submission);
    }

    /**
     * Parks a part-filled form and emails its resume link.
     *
     * Pro-gated, and defence in depth: the default template hides the save
     * control in Lite, but a custom template pack - or a hand-rolled post -
     * must not be able to open a path the edition doesn't include.
     */
    private function save(Submission $submission, FlowRequest $request, int $page): FlowResult
    {
        $form = $request->form;

        if (!$form->getFormSettings()->enableSaveAndResume || !Plugin::getInstance()->isPro()) {
            return FlowResult::saveUnavailable($submission, $page);
        }

        $email = self::normalizeEmail($request->resumeEmail);

        if ($email === null) {
            return FlowResult::saveFailed(
                $submission,
                $page,
                Craft::t('formable', 'Enter an email address to send your resume link to.'),
            );
        }

        $message = Craft::t('formable', 'We’ve emailed a link to {email} so you can finish later.', [
            'email' => $email,
        ]);

        // Save is the one anonymous step that both writes a row and sends mail,
        // so it is gated before it does either - first against a per-IP rate
        // limit (an unblocked bot looping this is a storage and a
        // sender-reputation problem at once), then against the mid-form spam
        // checks. A request turned away by either is answered exactly as a
        // saved one: naming the check would teach a bot how to slip past it,
        // and nothing was stored or sent regardless.
        if ($this->getGate()->isSaveLimited($form, $request->ipAddress)) {
            return FlowResult::saved($submission, $page, $message);
        }

        if ($this->isEarlySpam($request)) {
            return FlowResult::saved($submission, $page, $message);
        }

        $token = $this->getSubmissions()->saveIncomplete($submission, $page);

        if ($token === null) {
            return FlowResult::saveFailed(
                $submission,
                $page,
                Craft::t('formable', 'Your progress could not be saved. Please try again.'),
            );
        }

        // From here the flow is record-backed: every later page keeps the link
        // that has just gone out current.
        $this->getProgress()->attach($form, $submission, $request->flowId);
        Plugin::getInstance()->getNotifications()->sendResumeLink($form, $email, $token);

        return FlowResult::saved($submission, $page, $message);
    }

    /**
     * Whether the review step stands between this page and the submit.
     */
    private function wantsReview(Submission $submission, FlowRequest $request, int $page): bool
    {
        if (!$request->form->getFormSettings()->showReviewPage) {
            return false;
        }

        // The review step only exists once, after the genuinely last page, and
        // a move already carrying the review flag has come *from* it.
        return $this->isLastPage($submission, $page) && !$request->fromReview;
    }

    /**
     * Stores the answers so far, at the page the submitter is moving to.
     *
     * Ordinarily that is a write to their own session and nothing else; a form
     * they have parked through save-and-resume updates its stored row instead.
     * Either way the move is weighed against the mid-form spam checks first: a
     * request that has already given itself away gets to keep paging - saying
     * so would only teach a bot which check caught it - but has nothing kept
     * for it, and the full gate turns it away at the submit.
     */
    private function persist(Submission $submission, FlowRequest $request, int $targetPage): void
    {
        if ($this->isEarlySpam($request)) {
            return;
        }

        $this->getProgress()->persist($request->form, $submission, $targetPage, $request->flowId);
    }

    /**
     * Whether the honeypot, JS-presence and IP-blocklist checks already turn
     * this move away - the same mid-form gate {@see persist()} and
     * {@see save()} weigh internally, exposed publicly so a caller with
     * something to do before {@see handle()} runs can check first and skip
     * it - today, only an upload (see `SubmissionsController::actionSubmit()`
     * - an asset must not be written for a request the gate would have turned
     * away). Safe to call more than once for the same request: unlike the
     * rate limit, nothing here has a side effect.
     */
    public function isEarlySpam(FlowRequest $request): bool
    {
        return $this->getSpam()->evaluateEarly($request->form, $request->getSpamContext())->isSpam;
    }

    private static function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = trim($email);

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }
}
