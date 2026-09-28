<?php

declare(strict_types=1);

namespace bytesof\formable\controllers;

use bytesof\formable\elements\Submission;
use bytesof\formable\models\SpamReason;
use bytesof\formable\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The control-panel side of submissions: the element index, the detail/edit
 * view, and the notes that hang off it.
 *
 * Kept apart from the anonymous {@see SubmissionsController} that fields public
 * posts - nothing here is reachable without a session and the right permission,
 * so the two never share an `allowAnonymous` surface.
 *
 * @internal
 */
final class ManageSubmissionsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requireCpRequest();
        $this->requirePermission('formable:viewSubmissions');

        return true;
    }

    /**
     * The submissions element index, optionally deep-linked to one form's
     * source.
     */
    public function actionIndex(?string $formHandle = null): Response
    {
        $defaultSource = null;

        if ($formHandle !== null) {
            $form = Plugin::getInstance()->getForms()->getFormByHandle($formHandle);

            if ($form === null) {
                throw new NotFoundHttpException('Form not found');
            }

            $defaultSource = "form:$form->id";
        }

        return $this->renderTemplate('formable/submissions/_index', [
            'defaultSource' => $defaultSource,
        ]);
    }

    /**
     * The detail/edit view for one submission.
     */
    public function actionEdit(int $submissionId): Response
    {
        $submission = $this->findSubmission($submissionId);
        $form = $submission->getForm();

        if ($form === null) {
            throw new NotFoundHttpException('This submission’s form no longer exists.');
        }

        $plugin = Plugin::getInstance();

        return $this->renderTemplate('formable/submissions/_edit', [
            'submission' => $submission,
            'form' => $form,
            'fields' => $submission->getFormFields(),
            'statuses' => $plugin->getStatuses()->getAllStatuses(),
            'notes' => $plugin->getSubmissions()->getNotes($submissionId),
            'history' => $plugin->isPro()
                ? $plugin->getSubmissions()->getHistory($submissionId)
                : [],
            'canEdit' => $submission->canSave(Craft::$app->getUser()->getIdentity()),
            'proEnabled' => $plugin->isPro(),
            'spamReasonLabel' => SpamReason::labelFor($submission->spamReason),
            'title' => (string)$submission->title,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $submissionId = (int)$this->request->getRequiredBodyParam('submissionId');
        $submission = $this->findSubmission($submissionId);
        $this->requireSavePermission($submission);

        $plugin = Plugin::getInstance();
        $submissions = $plugin->getSubmissions();

        // The status change and the value edits are one save; capture the values
        // as they stand first, so the history diff reflects only this edit.
        $before = $submission->getValues();

        $statusId = $this->request->getBodyParam('statusId');
        $submission->statusId = $statusId !== null && $statusId !== '' ? (int)$statusId : null;

        $values = $this->request->getBodyParam('fields');
        $submissions->populateSubmission($submission, is_array($values) ? $values : []);

        if (!$submissions->validateSubmission($submission)) {
            $this->setFailFlash(Craft::t('formable', 'Couldn’t save submission.'));

            // Hand the rejected submission back to the edit view so the editor
            // sees their own values and the errors, not a reloaded copy.
            Craft::$app->getUrlManager()->setRouteParams([
                'submission' => $submission,
            ]);

            return $this->renderEditFromFailedSave($submission);
        }

        if (!$submissions->saveSubmission($submission)) {
            $this->setFailFlash(Craft::t('formable', 'Couldn’t save submission.'));

            return $this->renderEditFromFailedSave($submission);
        }

        // Edit history is a Pro feature; in Lite the values still save, they're
        // just not journalled.
        if ($plugin->isPro()) {
            $submissions->recordHistory(
                $submission,
                $submissions->diffValues($submission, $before, $submission->getValues()),
                Craft::$app->getUser()->getId(),
            );
        }

        $this->setSuccessFlash(Craft::t('formable', 'Submission saved.'));

        return $this->redirectToPostedUrl($submission);
    }

    /**
     * Flags a submission as spam, or clears the flag - the single-submission
     * counterpart to the index's bulk spam actions.
     */
    public function actionSetSpam(): Response
    {
        $this->requirePostRequest();

        $submissionId = (int)$this->request->getRequiredBodyParam('submissionId');
        $submission = $this->findSubmission($submissionId);
        $this->requireSavePermission($submission);

        $spam = (bool)$this->request->getBodyParam('spam');
        Plugin::getInstance()->getSubmissions()->setSpam($submission, $spam);

        $this->setSuccessFlash($spam
            ? Craft::t('formable', 'Marked as spam.')
            : Craft::t('formable', 'Marked as not spam.'));

        return $this->redirectToPostedUrl($submission);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();

        $submissionId = (int)$this->request->getRequiredBodyParam('submissionId');
        $submission = $this->findSubmission($submissionId);

        if (!$submission->canDelete(Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException('You don’t have permission to delete this submission.');
        }

        if (!Plugin::getInstance()->getSubmissions()->deleteSubmission($submission)) {
            throw new ForbiddenHttpException('Unable to delete submission.');
        }

        $this->setSuccessFlash(Craft::t('formable', 'Submission deleted.'));

        return $this->redirect('formable/submissions');
    }

    public function actionAddNote(): Response
    {
        $this->requirePostRequest();

        $submissionId = (int)$this->request->getRequiredBodyParam('submissionId');
        $submission = $this->findSubmission($submissionId);
        $this->requireSavePermission($submission);

        $note = (string)$this->request->getBodyParam('note', '');

        if (Plugin::getInstance()->getSubmissions()->addNote($submissionId, $note, Craft::$app->getUser()->getId()) === null) {
            $this->setFailFlash(Craft::t('formable', 'Enter a note.'));
        } else {
            $this->setSuccessFlash(Craft::t('formable', 'Note added.'));
        }

        return $this->redirectToPostedUrl($submission);
    }

    public function actionDeleteNote(): Response
    {
        $this->requirePostRequest();

        $submissionId = (int)$this->request->getRequiredBodyParam('submissionId');
        $submission = $this->findSubmission($submissionId);
        $this->requireSavePermission($submission);

        $noteId = (int)$this->request->getRequiredBodyParam('noteId');
        Plugin::getInstance()->getSubmissions()->deleteNoteById($noteId, $submissionId);

        $this->setSuccessFlash(Craft::t('formable', 'Note deleted.'));

        return $this->redirectToPostedUrl($submission);
    }

    private function findSubmission(int $id): Submission
    {
        $submission = Plugin::getInstance()->getSubmissions()->getSubmissionById($id);

        if ($submission === null) {
            throw new NotFoundHttpException('Submission not found');
        }

        return $submission;
    }

    private function requireSavePermission(Submission $submission): void
    {
        if (!$submission->canSave(Craft::$app->getUser()->getIdentity())) {
            throw new ForbiddenHttpException('You don’t have permission to edit this submission.');
        }
    }

    private function renderEditFromFailedSave(Submission $submission): Response
    {
        $plugin = Plugin::getInstance();
        $submissionId = (int)$submission->id;

        return $this->renderTemplate('formable/submissions/_edit', [
            'submission' => $submission,
            'form' => $submission->getForm(),
            'fields' => $submission->getFormFields(),
            'statuses' => $plugin->getStatuses()->getAllStatuses(),
            'notes' => $plugin->getSubmissions()->getNotes($submissionId),
            'history' => $plugin->isPro()
                ? $plugin->getSubmissions()->getHistory($submissionId)
                : [],
            'canEdit' => true,
            'proEnabled' => $plugin->isPro(),
            'spamReasonLabel' => SpamReason::labelFor($submission->spamReason),
            'title' => (string)$submission->title,
        ]);
    }
}
