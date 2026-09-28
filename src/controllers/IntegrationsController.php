<?php

declare(strict_types=1);

namespace bytesof\formable\controllers;

use bytesof\formable\Plugin;
use bytesof\formable\records\IntegrationRecord;
use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * CP management of global integration instances.
 *
 * Gated by `formable:manageIntegrations` throughout - a plain, delegable
 * permission, not admin. These records hold third-party credentials, which is
 * why the permission exists at all, but unlike Statuses/Settings they are a
 * plain table, not project config, so there's no `allowAdminChanges` bar on
 * top of it: an agency's forms operator can be granted this without being an
 * admin and without needing admin changes unlocked in production. Per-form
 * enablement and field mapping is a separate, form-editor-facing surface
 * handled in the builder.
 *
 * @internal
 */
final class IntegrationsController extends Controller
{
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission('formable:manageIntegrations');

        return true;
    }

    public function actionIndex(): Response
    {
        $plugin = Plugin::getInstance();

        return $this->renderTemplate('formable/integrations/_index', [
            'integrations' => $plugin->getIntegrations()->getAllIntegrations(),
            'types' => $plugin->getIntegrations()->getTypeDefinitions(),
            'isPro' => $plugin->isPro(),
        ]);
    }

    /**
     * The edit screen for a new or existing instance. A new one needs a `type`
     * so its connection-settings schema can be rendered.
     */
    public function actionEdit(?int $integrationId = null, ?string $type = null, ?IntegrationRecord $integration = null): Response
    {
        $plugin = Plugin::getInstance();
        $integrations = $plugin->getIntegrations();

        // $integration may already be set when re-rendering after a failed save.
        if ($integration === null) {
            if ($integrationId !== null) {
                $integration = $integrations->getIntegrationById($integrationId);

                if ($integration === null) {
                    throw new NotFoundHttpException('Integration not found');
                }
            } else {
                $integration = new IntegrationRecord();
                $integration->type = (string)$type;
                $integration->enabled = true;
            }
        }

        $typeClass = $integrations->getTypeClass((string)$integration->type);

        if ($typeClass === null) {
            throw new NotFoundHttpException('Unknown integration type');
        }

        $probe = new $typeClass();

        return $this->renderTemplate('formable/integrations/_edit', [
            'integration' => $integration,
            'settings' => $this->settingsArray($integration),
            'typeName' => $typeClass::displayName(),
            'schema' => $probe->getSettingsSchema(),
            'isNew' => $integration->id === null,
            'isPro' => $plugin->isPro(),
            'title' => $integration->id === null
                ? Craft::t('formable', 'New {type} integration', ['type' => $typeClass::displayName()])
                : (string)$integration->name,
        ]);
    }

    public function actionSave(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $request = $this->request;
        $id = $request->getBodyParam('integrationId');

        $integration = $id !== null && $id !== ''
            ? $plugin->getIntegrations()->getIntegrationById((int)$id)
            : new IntegrationRecord();

        if ($integration === null) {
            throw new NotFoundHttpException('Integration not found');
        }

        $saved = $plugin->getIntegrations()->saveIntegration($integration, [
            'name' => $request->getBodyParam('name'),
            'handle' => $request->getBodyParam('handle'),
            'type' => $request->getBodyParam('type', $integration->type),
            'enabled' => (bool)$request->getBodyParam('enabled', true),
            'settings' => $request->getBodyParam('settings', []),
        ]);

        if (!$saved) {
            $this->setFailFlash(Craft::t('formable', 'Couldn’t save integration.'));

            // Hand the populated record back to the edit action so its errors and
            // entered values survive the round-trip.
            Craft::$app->getUrlManager()->setRouteParams([
                'integration' => $integration,
            ]);

            return $this->renderTemplate('formable/integrations/_edit', $this->reeditParams($integration));
        }

        $this->setSuccessFlash(Craft::t('formable', 'Integration saved.'));

        return $this->redirectToPostedUrl();
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $id = (int)$this->request->getRequiredBodyParam('id');

        return $this->asJson([
            'success' => Plugin::getInstance()->getIntegrations()->deleteIntegrationById($id),
        ]);
    }

    /**
     * Tests the connection for the settings currently entered on the edit
     * screen, saved or not - so an admin can confirm credentials before saving.
     */
    public function actionTestConnection(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $type = (string)$this->request->getRequiredBodyParam('type');
        $settings = $this->request->getBodyParam('settings', []);

        $response = Plugin::getInstance()->getIntegrations()->testConnection(
            $type,
            is_array($settings) ? $settings : [],
        );

        return $this->asJson([
            'success' => $response->success,
            'message' => $response->success
                ? Craft::t('formable', 'Connection successful.')
                : ($response->message ?? Craft::t('formable', 'Connection failed.')),
        ]);
    }

    /**
     * The template variables for re-rendering the edit screen after a failed
     * save.
     *
     * @return array<string, mixed>
     */
    private function reeditParams(IntegrationRecord $integration): array
    {
        $typeClass = Plugin::getInstance()->getIntegrations()->getTypeClass((string)$integration->type);
        $probe = $typeClass !== null ? new $typeClass() : null;

        return [
            'integration' => $integration,
            'settings' => $this->settingsArray($integration),
            'typeName' => $typeClass !== null ? $typeClass::displayName() : (string)$integration->type,
            'schema' => $probe?->getSettingsSchema() ?? [],
            'isNew' => $integration->id === null,
            'isPro' => Plugin::getInstance()->isPro(),
            'title' => Craft::t('formable', 'Edit integration'),
        ];
    }

    /**
     * A record's settings as an array, decoding the json column if it came back
     * as a string.
     *
     * @return array<string, mixed>
     */
    private function settingsArray(IntegrationRecord $integration): array
    {
        $settings = $integration->settings;

        if (is_string($settings)) {
            $settings = Json::decodeIfJson($settings);
        }

        return is_array($settings) ? $settings : [];
    }
}
