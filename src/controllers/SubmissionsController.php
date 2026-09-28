<?php

declare(strict_types=1);

namespace bytesof\formable\controllers;

use bytesof\formable\base\FormField;
use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\models\FlowRequest;
use bytesof\formable\models\FlowResult;
use bytesof\formable\models\FormSettings;
use bytesof\formable\models\PageFlowStep;
use bytesof\formable\models\SpamContext;
use bytesof\formable\Plugin;
use bytesof\formable\services\PageFlow;
use bytesof\formable\services\Progress;
use bytesof\formable\services\Rendering;
use bytesof\formable\services\Spam;
use Craft;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The public submission endpoint.
 *
 * This is transport and nothing else. A post is read into a {@see FlowRequest},
 * {@see PageFlow} decides what the move means, and the {@see FlowResult} that
 * comes back is reported in whichever language the caller speaks: a redirect and
 * a flash for a plain HTML post, JSON for the AJAX path. Both run the identical
 * flow - the only thing that differs is how the outcome is announced, so JS can
 * never reach a check the non-JS path doesn't.
 *
 * Multi-page forms are rendered one page at a time and their answers gathered
 * into one working submission held for the session by
 * {@see \bytesof\formable\services\Progress} - the same mechanism whether or not
 * JavaScript is running. JavaScript just swaps in the next page's markup from
 * the JSON response instead of following a redirect.
 *
 * @internal
 */
final class SubmissionsController extends Controller
{
    /** @var array<int, string>|int|bool */
    protected array|int|bool $allowAnonymous = ['submit', 'resume'];

    public function actionSubmit(): ?Response
    {
        $this->requirePostRequest();

        $form = $this->resolveForm();

        // A closed form - switched off, or outside its schedule window on Pro -
        // is turned away with its closed message rather than a hard 404, so a
        // page that was open when the form shut renders the message instead of
        // an error. A form that never existed still 404s, from resolveForm().
        if (!$form->isAcceptingSubmissions()) {
            return $this->respondClosed($form);
        }

        $flow = Plugin::getInstance()->getPageFlow();

        $request = $this->flowRequest($form);
        $submission = $flow->begin($form, $request->flowId);

        $flow->populate($submission, $request);

        // After the posted values, never before: an upload's asset IDs are the
        // field's value, and populating over them would blank them out. And
        // only behind the same early spam gate handle() itself checks -
        // otherwise a honeypot-tripped bot gets a real Asset written to the
        // volume before anything ever turns the request away.
        if (!$flow->isEarlySpam($request)) {
            $uploads = Plugin::getInstance()->getUploads();
            $pageIndex = $flow->resolvePage($form, $submission, $request->page);
            $pendingHandles = $uploads->getPendingUploadHandles($form, $pageIndex);

            // Nothing to gate, and nothing to write, when the page carries no
            // file for any of its File Upload fields.
            if ($pendingHandles !== []) {
                // The rest of the page - every field but the ones a pending
                // upload belongs to - has to be worth writing the file for.
                // Without this, a page that was always going to fail
                // validation for an unrelated reason (a blank required field
                // alongside the upload) still got a real Asset written and
                // then discarded with it - N9's orphaned-asset finding. The
                // upload fields themselves are excluded here because their
                // own requiredness can't be judged before the file exists;
                // handle() re-validates them a moment later, against the real
                // asset ids once handlePageUploads() below has set them.
                // Checked first, and short-circuiting the rate limit below, so
                // a submitter fixing a typo and reposting doesn't spend the
                // upload budget on attempts that were never going to write
                // anything.
                $pageIsOtherwiseValid = $flow->getSubmissions()->pageValidatesExcluding(
                    $submission,
                    $pageIndex,
                    $pendingHandles,
                );

                // A separate bucket from the submit pipeline's own, keyed to
                // the same numbers - checked once, before anything is
                // written, so an unbounded upload loop can't be run behind a
                // rate limit that only ever gates the row it's attached to.
                $limited = $pageIsOtherwiseValid
                    && Plugin::getInstance()->getSubmissionGate()->isUploadLimited($form, $request->ipAddress);

                // Silent, like every other throttle here: an over-cap or
                // otherwise-invalid request still gets its ordinary success
                // or validation answer, just with nothing uploaded for it.
                if ($pageIsOtherwiseValid && !$limited) {
                    $uploads->handlePageUploads($form, $submission, $pageIndex);
                }
            }
        }

        return $this->respond($form, $flow->handle($submission, $request));
    }

    /**
     * Opens a saved form from a resume link.
     *
     * The token is the whole credential, so a bad or expired one 404s rather
     * than leaking whether that token ever existed.
     */
    public function actionResume(): Response
    {
        // Save-and-resume is a Pro feature. In Lite no resume link is ever
        // issued, so an incoming one 404s like any other invalid token rather
        // than leaking that the feature exists.
        if (!Plugin::getInstance()->isPro()) {
            throw new NotFoundHttpException('This link has expired or is no longer valid.');
        }

        $handle = $this->request->getRequiredParam('form');
        $token = $this->request->getRequiredParam('token');

        $form = Plugin::getInstance()->getForms()->getFormByHandle(is_string($handle) ? $handle : '');

        if ($form === null || !$form->enabled) {
            throw new NotFoundHttpException('Form not found.');
        }

        $submission = Plugin::getInstance()->getSubmissions()->getSubmissionByResumeToken(
            $form,
            is_string($token) ? $token : '',
        );

        if ($submission === null) {
            throw new NotFoundHttpException('This link has expired or is no longer valid.');
        }

        // Re-attach the resumed submission to this session, so the submitter
        // can carry on paging through it exactly as if they'd never left - and
        // so the row they were promised keeps up with the pages they fill in
        // from here. The landing page is a fresh pass through the form, so it
        // gets a fresh flow id, planted in the shell it is about to render.
        $flowId = Plugin::getInstance()->getProgress()->newFlowId();
        Plugin::getInstance()->getProgress()->attach($form, $submission, $flowId);

        return $this->renderResumedForm($form, $submission, $flowId);
    }

    /**
     * Edits one of the logged-in visitor's own submissions.
     *
     * The gate is ownership, checked server-side against the stored `userId` -
     * a member can only ever reach a row that's theirs, whatever id they post.
     */
    public function actionUpdateOwn(): ?Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $submission = $this->resolveOwnSubmission();
        $form = $submission->getForm();

        if ($form === null) {
            throw new NotFoundHttpException('Form not found.');
        }

        $submissions = Plugin::getInstance()->getSubmissions();
        $before = $submission->getValues();

        $values = $this->request->getBodyParam('fields');
        $submissions->populateSubmission($submission, is_array($values) ? $values : []);

        Plugin::getInstance()->getUploads()->handlePageUploads(
            $form,
            $submission,
            Plugin::getInstance()->getPageFlow()->resolvePage($form, $submission, $this->postedPage()),
        );

        // Same rule the original submission was subject to: an answer behind
        // a condition the new values just hid must not survive the edit.
        $submissions->applyConditions($submission);

        if (!$submissions->validateSubmission($submission)) {
            if ($this->request->getAcceptsJson()) {
                return $this->asJson([
                    'success' => false,
                    'errors' => $submission->getAllFieldErrors(),
                    'formErrors' => $submission->getNonFieldErrors(),
                ]);
            }

            $this->setFailFlash(Craft::t('formable', 'There was a problem with your submission. Please check the fields below.'));
            Craft::$app->getUrlManager()->setRouteParams(['formableSubmission' => $submission]);

            return null;
        }

        if (!$submissions->saveSubmission($submission)) {
            throw new ForbiddenHttpException('The submission could not be saved.');
        }

        if (Plugin::getInstance()->isPro()) {
            $submissions->recordHistory(
                $submission,
                $submissions->diffValues($submission, $before, $submission->getValues()),
                Craft::$app->getUser()->getId(),
            );
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true, 'submissionId' => $submission->id]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('formable', 'Submission updated.'));

        return $this->redirectToPostedUrl($submission);
    }

    /**
     * Deletes one of the logged-in visitor's own submissions.
     */
    public function actionDeleteOwn(): Response
    {
        $this->requirePostRequest();
        $this->requireLogin();

        $submission = $this->resolveOwnSubmission();

        if (!Plugin::getInstance()->getSubmissions()->deleteSubmission($submission)) {
            throw new ForbiddenHttpException('The submission could not be deleted.');
        }

        if ($this->request->getAcceptsJson()) {
            return $this->asJson(['success' => true]);
        }

        Craft::$app->getSession()->setNotice(Craft::t('formable', 'Submission deleted.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Loads a submission the current visitor owns, 404-ing on anything that
     * isn't theirs - a completed submission, tied to their user id.
     *
     * Front-end submission access is a Pro feature; in Lite this is closed
     * even to a submission's own author. On Pro it's additionally a per-form
     * opt-in ({@see FormSettings::$enableSelfService}): without it, both
     * actions 404 exactly as if the submission didn't exist, so a site that
     * never built a self-service page - and never turned the setting on -
     * isn't quietly editable by direct POST. A form that has opted in still
     * refuses once it stops accepting submissions, the same closed/scheduled
     * check the public submit endpoint honours.
     */
    private function resolveOwnSubmission(): Submission
    {
        if (!Plugin::getInstance()->isPro()) {
            throw new ForbiddenHttpException(
                Craft::t('formable', 'Managing your own submissions is available in Formable Pro.'),
            );
        }

        $id = (int)$this->request->getRequiredBodyParam('submissionId');
        $submission = Plugin::getInstance()->getSubmissions()->getSubmissionById($id);
        $userId = Craft::$app->getUser()->getId();

        if (
            $submission === null
            || $submission->isIncomplete
            || $submission->userId === null
            || (string)$submission->userId !== (string)$userId
        ) {
            throw new NotFoundHttpException('Submission not found.');
        }

        $form = $submission->getForm();

        if ($form === null || !$form->getFormSettings()->enableSelfService) {
            throw new NotFoundHttpException('Submission not found.');
        }

        if (!$form->isAcceptingSubmissions()) {
            throw new ForbiddenHttpException(
                Craft::t('formable', 'This form is no longer accepting changes.'),
            );
        }

        return $submission;
    }

    /**
     * Reads the post into the move it describes.
     *
     * Everything here is untrusted input in its rawest usable form - the page
     * index is a hint, the resume email is whatever was typed, the step is
     * narrowed to one the flow knows. What any of it is allowed to do is
     * {@see PageFlow}'s business, not this method's.
     */
    private function flowRequest(Form $form): FlowRequest
    {
        $values = $this->request->getBodyParam('fields');
        $subValues = $this->request->getBodyParam(FormField::SUB_VALUE_PARAM);
        $resumeEmail = $this->request->getBodyParam('formableResumeEmail');
        $goto = $this->request->getBodyParam('formableGoto');

        return new FlowRequest(
            form: $form,
            step: PageFlowStep::normalize($this->request->getBodyParam('formableStep')),
            page: $this->postedPage(),
            values: is_array($values) ? $values : [],
            subValues: is_array($subValues) ? $subValues : [],
            goto: is_numeric($goto) ? (int)$goto : null,
            fromReview: (bool)$this->request->getBodyParam('formableReview'),
            spam: $this->spamContext($form),
            resumeEmail: is_string($resumeEmail) ? $resumeEmail : null,
            userId: Craft::$app->getUser()->getId(),
            ipAddress: $this->request->getUserIP(),
            userAgent: $this->request->getUserAgent(),
            flowId: Progress::normalizeFlowId($this->request->getBodyParam('formableFlow')),
        );
    }

    private function postedPage(): ?int
    {
        $posted = $this->request->getBodyParam('pageIndex');

        return is_numeric($posted) ? (int)$posted : null;
    }

    /**
     * Gathers the request's spam signals for a form.
     *
     * The captcha token's field name is provider-specific, so the form's
     * configured captcha (if any) is what names it. The IP is read here whether
     * or not the form stores it - spam scoring needs it regardless of the GDPR
     * opt-in that governs *keeping* it.
     */
    private function spamContext(Form $form): SpamContext
    {
        $captcha = Plugin::getInstance()->getCaptchas()->getCaptchaForForm($form);

        return new SpamContext(
            honeypot: $this->stringParam(Plugin::getInstance()->getSettings()->honeypotFieldName),
            jsToken: $this->stringParam(Spam::JS_FIELD),
            timeToken: $this->stringParam(Spam::TIME_FIELD),
            captchaToken: $captcha !== null ? $this->stringParam($captcha->responseParam()) : null,
            ipAddress: $this->request->getUserIP(),
        );
    }

    private function stringParam(string $name): ?string
    {
        $value = $this->request->getBodyParam($name);

        return is_string($value) ? $value : null;
    }

    /**
     * The form being posted to.
     *
     * Only a form that doesn't exist 404s here. Whether an existing form is
     * actually open - enabled, untrashed, inside its schedule window - is a
     * separate question answered by {@see Form::isAcceptingSubmissions()}, so a
     * closed form can be turned away with its message instead of an error.
     */
    private function resolveForm(): Form
    {
        $handle = $this->request->getBodyParam('handle');

        if (!is_string($handle) || $handle === '') {
            throw new BadRequestHttpException('No form handle was submitted.');
        }

        $form = Plugin::getInstance()->getForms()->getFormByHandle($handle);

        if ($form === null) {
            throw new NotFoundHttpException('Form not found.');
        }

        return $form;
    }

    /**
     * Says what the flow decided, in the language this request speaks.
     */
    private function respond(Form $form, FlowResult $result): ?Response
    {
        $settings = $form->getFormSettings();

        return match ($result->status) {
            FlowResult::ADVANCED => $this->respondAdvanced($form, $result),
            FlowResult::COMPLETED => $this->respondCompleted($form, $result, $settings),
            FlowResult::SAVED => $this->respondSaved($result),
            FlowResult::SAVE_FAILED => $this->respondSaveError($result),
            // A form that cannot be saved for later has no save control to
            // press, so a save reaching here is a hand-rolled post rather than
            // anything a submitter could do - answered as the bad request it is.
            FlowResult::SAVE_UNAVAILABLE => throw new BadRequestHttpException('This form cannot be saved for later.'),
            default => $this->respondInvalid($form, $result),
        };
    }

    /**
     * Moves to another page: JSON for AJAX (the client owns the DOM), a
     * redirect back to the same URL for the non-JS path (the template renders
     * the new page).
     */
    private function respondAdvanced(Form $form, FlowResult $result): Response
    {
        if ($this->request->getAcceptsJson()) {
            // The target step's markup rides along, so the client swaps the
            // page in without a second request and without owning any
            // knowledge of how a page is built.
            $step = Plugin::getInstance()->getRendering()->renderStep(
                $form,
                $result->submission,
                $result->page,
                $result->isReview,
            );

            return $this->asJson([
                'success' => true,
                'page' => $result->page,
                'review' => $result->isReview,
                'html' => $step !== null ? (string)$step : null,
            ]);
        }

        return $this->redirectToStep($result->page, $result->isReview);
    }

    private function respondCompleted(Form $form, FlowResult $result, FormSettings $settings): Response
    {
        $rendering = Plugin::getInstance()->getRendering();
        $translations = Plugin::getInstance()->getTranslations();
        $submission = $result->submission;
        $message = $rendering->renderMessage($translations->settingString($form, 'successMessage'), $submission);
        $successDetails = $translations->settingString($form, 'successDetails');
        $details = $successDetails !== ''
            ? $rendering->renderMessage($successDetails, $submission)
            : null;

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'submissionId' => $submission->id,
                'message' => $message,
                'successDetails' => $details,
                // Fixed UI chrome, not author-configurable copy, so it's
                // translated here rather than becoming a settings field - the
                // client has no other route to a translated string.
                'restartLabel' => $settings->successBehavior === FormSettings::SUCCESS_MESSAGE
                    ? Craft::t('formable', 'Submit another response')
                    : null,
                'redirect' => $settings->successBehavior === FormSettings::SUCCESS_REDIRECT
                    ? $rendering->renderRedirectUrl($settings, $submission)
                    : null,
            ]);
        }

        if ($settings->successBehavior === FormSettings::SUCCESS_REDIRECT) {
            $url = $rendering->renderRedirectUrl($settings, $submission);

            if ($url !== null) {
                return $this->redirect($url);
            }
        }

        // Flashed under the form's own handle, not Craft's generic `notice`
        // slot: the renderer reads this back on the redirect and prints it in
        // the form's own success region, and a page carrying two forms shows
        // the message only on the one that was actually submitted. Setting the
        // generic flash as well would make a theme that renders Craft's flashes
        // show the same message a second time.
        Craft::$app->getSession()->setFlash(
            Rendering::SUCCESS_FLASH_PREFIX . $form->handle,
            $message,
        );

        if ($details !== null) {
            Craft::$app->getSession()->setFlash(
                Rendering::SUCCESS_DETAILS_FLASH_PREFIX . $form->handle,
                $details,
            );
        }

        return $this->redirectToPostedUrl($submission);
    }

    private function respondInvalid(Form $form, FlowResult $result): ?Response
    {
        $submission = $result->submission;
        $errorMessage = Plugin::getInstance()->getRendering()->renderMessage(
            Plugin::getInstance()->getTranslations()->settingString($form, 'errorMessage'),
            $submission,
        );

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => false,
                'message' => $errorMessage,
                'errors' => $submission->getAllFieldErrors(),
                'formErrors' => $submission->getNonFieldErrors(),
            ]);
        }

        $this->setFailFlash($errorMessage);
        $this->rerenderAt($result);

        return null;
    }

    private function respondSaved(FlowResult $result): Response
    {
        $message = (string)$result->message;

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => true,
                'saved' => true,
                'message' => $message,
            ]);
        }

        Craft::$app->getSession()->setNotice($message);

        return $this->redirectToStep($result->page);
    }

    private function respondSaveError(FlowResult $result): ?Response
    {
        $message = (string)$result->message;

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => false,
                'saved' => false,
                'message' => $message,
            ]);
        }

        $this->setFailFlash($message);
        $this->rerenderAt($result);

        return null;
    }

    /**
     * Turns away a post to a closed form.
     *
     * The AJAX path gets JSON carrying a `closed` flag so the client can tell a
     * shut form apart from a validation failure; the non-JS path bounces back to
     * the page the form lives on, where the renderer now shows the closed
     * message in place of the fields.
     */
    private function respondClosed(Form $form): Response
    {
        $message = Plugin::getInstance()->getRendering()->getClosedMessage($form);

        if ($this->request->getAcceptsJson()) {
            return $this->asJson([
                'success' => false,
                'closed' => true,
                'message' => $message,
            ]);
        }

        $this->setFailFlash($message);

        return $this->redirect($this->request->getReferrer() ?? UrlHelper::siteUrl());
    }

    /**
     * Renders a resumed form as a standalone page, at the point the submitter
     * left off.
     */
    private function renderResumedForm(Form $form, Submission $submission, string $flowId): Response
    {
        $view = Craft::$app->getView();
        $this->response->data = $view->renderPageTemplate(
            Rendering::TEMPLATE_ROOT . '/_resume',
            [
                'form' => $form,
                'submission' => $submission,
                'page' => $submission->pageIndex,
                'flowId' => $flowId,
            ],
            View::TEMPLATE_MODE_SITE,
        );

        return $this->response;
    }

    /**
     * Hands the rejected submission - and the page the submitter was on - to
     * the template that re-renders the form, so they get their own values back
     * with the errors attached instead of an empty form.
     */
    private function rerenderAt(FlowResult $result): void
    {
        Craft::$app->getUrlManager()->setRouteParams([
            'formableSubmission' => $result->submission,
            'formablePage' => $result->page,
        ]);
    }

    private function redirectToStep(int $page, bool $review = false): Response
    {
        $url = $this->request->getReferrer() ?? $this->request->getUrl();
        $params = ['formablePage' => $page];

        // Carry the flow id back onto the URL the non-JS path reloads, so the
        // re-rendered shell plants the same one and its progress is found again
        // rather than started over.
        $flowId = Progress::normalizeFlowId($this->request->getBodyParam('formableFlow'));

        if ($flowId !== null) {
            $params['formableFlow'] = $flowId;
        }

        if ($review) {
            $params['formableReview'] = 1;
        }

        return $this->redirect(UrlHelper::urlWithParams($url, $params));
    }
}
