<?php

declare(strict_types=1);

namespace bytesof\formable\controllers;

use bytesof\formable\models\Status;
use bytesof\formable\Plugin;
use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP management of submission statuses.
 *
 * Gated by `formable:manageStatuses`, a delegable permission - but statuses
 * are project config, so on top of it this stays unavailable wherever admin
 * changes are locked down, for anyone, admins included: what is edited here
 * is deployed, not entered, the same bar Craft applies to its own
 * project-config editors. Unlike that bar, the permission itself can be
 * granted to a non-admin, e.g. a forms operator who needs to add a workflow
 * status but nothing else.
 *
 * @internal
 */
final class StatusesController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('formable:manageStatuses');

        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            throw new ForbiddenHttpException('Administrative changes are disallowed in this environment.');
        }

        return true;
    }

    public function actionIndex(): Response
    {
        return $this->renderTemplate('formable/statuses/_index', [
            'statuses' => Plugin::getInstance()->getStatuses()->getAllStatuses(),
        ]);
    }

    /**
     * The edit screen for a new or existing status. `$status` is only passed
     * when re-rendering after a failed save.
     */
    public function actionEdit(?int $statusId = null, ?Status $status = null): Response
    {
        $statuses = Plugin::getInstance()->getStatuses();

        if ($status === null) {
            if ($statusId !== null) {
                $status = $statuses->getStatusById($statusId);

                if ($status === null) {
                    throw new NotFoundHttpException('Status not found');
                }
            } else {
                $status = new Status();
            }
        }

        $isNew = $status->id === null;

        return $this->renderTemplate('formable/statuses/_edit', [
            'status' => $status,
            'isNew' => $isNew,
            // The default can be moved to another status but not simply turned
            // off, so on the status that holds it the switch is shown locked
            // rather than offered and then overruled.
            'isDefault' => !$isNew && $statuses->getDefaultStatus()?->id === $status->id,
            'title' => $isNew
                ? Craft::t('formable', 'New submission status')
                : $status->name,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $statuses = Plugin::getInstance()->getStatuses();
        $id = $this->request->getBodyParam('statusId');
        $existing = $id !== null && $id !== ''
            ? ($statuses->getStatusById((int)$id) ?? throw new NotFoundHttpException('Status not found'))
            : null;

        // A clone: the service hands out the models it memoizes, and editing
        // one in place would change what the rest of the request reads - not
        // least the check for which status currently holds the default.
        $status = $existing !== null ? clone $existing : new Status();

        $status->name = (string)$this->request->getBodyParam('name', '');
        $status->handle = (string)$this->request->getBodyParam('handle', '');
        $status->color = (string)$this->request->getBodyParam('color', 'blue');
        $status->isDefault = (bool)$this->request->getBodyParam('isDefault', false);

        if (!$statuses->saveStatus($status)) {
            $this->setFailFlash(Craft::t('formable', 'Couldn’t save status.'));

            return $this->actionEdit(status: $status);
        }

        $this->setSuccessFlash(Craft::t('formable', 'Status saved.'));

        return $this->redirectToPostedUrl();
    }

    public function actionReorder(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        /** @var array<int, mixed> $ids */
        $ids = Json::decode($this->request->getRequiredBodyParam('ids'));

        Plugin::getInstance()->getStatuses()->reorderStatuses(array_map('intval', $ids));

        return $this->asSuccess();
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $statuses = Plugin::getInstance()->getStatuses();
        $status = $statuses->getStatusById((int)$this->request->getRequiredBodyParam('id'));

        if ($status === null) {
            return $this->asFailure(Craft::t('formable', 'That status no longer exists.'));
        }

        // Deleting works on a clone for the same reason saving does: a refusal
        // is recorded as an error on the model, and the memoized one is shared.
        $status = clone $status;

        if (!$statuses->deleteStatus($status)) {
            // Each refusal names what the admin has to do first.
            $errors = $status->getFirstErrors();

            return $this->asFailure(reset($errors) ?: Craft::t('formable', 'Couldn’t delete status.'));
        }

        return $this->asSuccess(Craft::t('formable', 'Status deleted.'));
    }
}
