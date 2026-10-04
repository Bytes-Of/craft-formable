<?php

declare(strict_types=1);

namespace bytesof\formable\controllers;

use bytesof\formable\elements\Form;
use bytesof\formable\models\ConditionRule;
use bytesof\formable\models\ConditionSet;
use bytesof\formable\models\FormSettings;
use bytesof\formable\models\Notification;
use bytesof\formable\models\ResendResult;
use bytesof\formable\Plugin;
use bytesof\formable\services\Layout;
use bytesof\formable\services\Notifications;
use bytesof\formable\services\Presets;
use bytesof\formable\web\assets\formbuilder\FormBuilderAsset;
use Craft;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\App;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\services\ElementSources;
use craft\web\Controller;
use craft\web\View;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

/**
 * CP form CRUD, plus the endpoints the Vue builder talks to.
 *
 * @internal
 */
final class FormsController extends Controller
{
    public function actionIndex(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('formable:viewForms');

        return $this->renderTemplate('formable/forms/_index');
    }

    /**
     * The first step of creating a form: pick a starter preset.
     *
     * A light server-rendered chooser, so the Vue builder only ever boots with
     * a layout already in hand. Choosing a card lands on {@see actionCreate()}.
     */
    public function actionNew(): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('formable:saveForms');

        return $this->renderTemplate('formable/forms/_new', [
            'title' => Craft::t('formable', 'Create a new form'),
            'presets' => Plugin::getInstance()->getPresets()->getAllPresets(),
            'createUrl' => UrlHelper::cpUrl('formable/forms/create'),
        ]);
    }

    /**
     * Opens the builder on a new form seeded from the chosen preset.
     *
     * Seeds only the in-memory layout - nothing is written until the builder
     * posts to {@see actionSave()} - so an unknown or missing preset simply
     * falls back to the default rather than failing.
     */
    public function actionCreate(?string $preset = null): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('formable:saveForms');

        $presets = Plugin::getInstance()->getPresets();
        $chosen = $preset !== null ? $presets->getPresetByHandle($preset) : null;
        $chosen ??= $presets->getPresetByHandle(Presets::DEFAULT_HANDLE);

        $form = new Form();
        $form->setPages($chosen->pages ?? []);

        return $this->renderBuilder($form, true);
    }

    /**
     * Renders the builder shell for an existing form.
     *
     * A bare `forms/edit` with no ID (an old link, since the New button now
     * routes through the picker) starts a new form from the default preset.
     */
    public function actionEdit(?int $formId = null): Response
    {
        $this->requireCpRequest();
        $this->requirePermission('formable:viewForms');

        if ($formId === null) {
            return $this->actionCreate(Presets::DEFAULT_HANDLE);
        }

        $form = Plugin::getInstance()->getForms()->getFormById($formId);

        if ($form === null) {
            throw new NotFoundHttpException('Form not found');
        }

        return $this->renderBuilder($form, false);
    }

    /**
     * Renders the builder shell and hands the Vue app everything it needs as one
     * JSON blob, so it boots without a round-trip.
     */
    private function renderBuilder(Form $form, bool $isNew): Response
    {
        Craft::$app->getView()->registerAssetBundle(FormBuilderAsset::class);

        return $this->renderTemplate('formable/forms/_edit', [
            'form' => $form,
            'isNew' => $isNew,
            'title' => $isNew
                ? Craft::t('formable', 'Create a new form')
                : (string)$form->title,
            'builderConfig' => $this->builderConfig($form, $isNew),
        ]);
    }

    /**
     * Saves a form and its layout from the builder's JSON payload.
     *
     * Always answers with JSON - the builder is the only caller.
     */
    public function actionSave(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('formable:saveForms');

        $plugin = Plugin::getInstance();
        $request = $this->request;
        $formId = $request->getBodyParam('formId');

        if ($formId !== null && $formId !== '') {
            $form = $plugin->getForms()->getFormById((int)$formId);

            if ($form === null) {
                throw new NotFoundHttpException('Form not found');
            }
        } else {
            $form = new Form();
        }

        $form->title = (string)$request->getBodyParam('title', $form->title);
        $form->handle = (string)$request->getBodyParam('handle', $form->handle);
        $form->enabled = (bool)$request->getBodyParam('enabled', $form->enabled);

        $defaultStatusId = $request->getBodyParam('defaultStatusId');
        $form->defaultStatusId = $defaultStatusId !== null && $defaultStatusId !== ''
            ? (int)$defaultStatusId
            : null;

        $pages = $plugin->getLayout()->normalizePages($request->getBodyParam('pages'));
        $form->setPages($pages);

        $settings = new FormSettings();
        $settings->setAttributes($this->arrayParam('settings'), false);
        $form->setSettings($settings->toArray());

        $form->setTranslations($this->arrayParam('translations'));

        $notifications = $this->listParam('notifications');

        $layoutErrors = $plugin->getLayout()->validatePages($pages);
        $fieldHandles = $plugin->getLayout()->getFieldHandles($pages);
        $notificationErrors = $plugin->getNotifications()->validateNotifications($notifications, $fieldHandles);
        $settingsValid = $settings->validate();

        foreach ($plugin->getIntegrations()->validateFieldReferences($settings->integrations, $fieldHandles) as $message) {
            $settings->addError('integrations', $message);
            $settingsValid = false;
        }
        $formValid = $form->validate();

        if (
            !$formValid
            || !$settingsValid
            || $plugin->getLayout()->hasErrors($layoutErrors)
            || $notificationErrors !== []
        ) {
            return $this->asJson([
                'success' => false,
                'errors' => [
                    'form' => $form->getErrors(),
                    'settings' => $settings->getErrors(),
                    'layout' => $layoutErrors,
                    'notifications' => $notificationErrors,
                ],
            ]);
        }

        // Validation already ran above; re-running it would only repeat the work.
        if (!$plugin->getForms()->saveFormWithNotifications($form, $notifications, false)) {
            return $this->asJson([
                'success' => false,
                'errors' => [
                    'form' => $form->getErrors(),
                    'settings' => [],
                    'layout' => $plugin->getLayout()->validatePages($pages),
                    'notifications' => [],
                ],
            ]);
        }

        $renamed = $form->getRenamedHandles();

        return $this->asJson([
            'success' => true,
            'id' => $form->id,
            'title' => $form->title,
            'handle' => $form->handle,
            'renamedHandles' => $renamed,
            'staleTokenNotifications' => $form->id !== null
                ? $plugin->getNotifications()->findStaleTokenNotifications($form->id, $renamed)
                : [],
            'dateUpdated' => $form->dateUpdated?->getTimestamp(),
            'redirect' => $form->id !== null
                ? UrlHelper::cpUrl("formable/forms/$form->id")
                : null,
            'message' => Craft::t('formable', 'Form saved.'),
        ]);
    }

    /**
     * How many submissions a saved form has stored.
     *
     * The builder calls this before committing a rename of an already-saved
     * field's handle, so it can warn with a concrete count rather than a
     * generic "this might affect existing data".
     */
    public function actionSubmissionCount(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('formable:saveForms');

        $formId = (int)$this->request->getRequiredBodyParam('formId');

        return $this->asJson([
            'count' => Plugin::getInstance()->getSubmissions()->getSubmissionCount($formId),
        ]);
    }

    /**
     * Sends a test copy of a notification - as currently edited in the builder,
     * saved or not - against a submission of sample values.
     */
    public function actionTestNotification(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('formable:saveForms');

        $plugin = Plugin::getInstance();
        $formId = $this->request->getBodyParam('formId');

        $form = $formId !== null && $formId !== ''
            ? $plugin->getForms()->getFormById((int)$formId)
            : null;

        if ($form === null) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('formable', 'Save the form before sending a test.'),
            ]);
        }

        $notification = Notification::fromArray($this->arrayParam('notification'));
        $to = Notifications::parseAddressList((string)$this->request->getBodyParam('to', ''));

        // Default to the logged-in user, so the common case (“send me one”)
        // needs no address typed.
        if ($to === []) {
            $email = Craft::$app->getUser()->getIdentity()?->email;

            if ($email !== null) {
                $to = [$email];
            }
        }

        if ($to === []) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('formable', 'Enter an address to send the test to.'),
            ]);
        }

        $submission = $plugin->getNotifications()->createSampleSubmission($form);

        if (!$plugin->getNotifications()->sendTest($notification, $submission, $to)) {
            return $this->asJson([
                'success' => false,
                'message' => Craft::t('formable', 'The test could not be sent. Check your email settings and the logs.'),
            ]);
        }

        return $this->asJson([
            'success' => true,
            'message' => Craft::t('formable', 'Test sent to {recipients}.', [
                'recipients' => implode(', ', $to),
            ]),
        ]);
    }

    /**
     * Resends one failed notification from the builder's delivery log, and
     * answers with the refreshed log.
     *
     * The log comes back whatever the outcome because the server, not the
     * builder, decides which rows can still be resent: after a success the row
     * loses its button, and after another failure the new row is the one that
     * carries it.
     */
    public function actionResendNotification(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('formable:saveForms');

        $notifications = Plugin::getInstance()->getNotifications();
        $result = $notifications->resend((int)$this->request->getRequiredBodyParam('logId'));

        return $this->asResendResult(
            $result,
            $notifications->getRecentLogs((int)$this->request->getRequiredBodyParam('formId')),
        );
    }

    /**
     * The integrations counterpart of {@see actionResendNotification()}. Lite is
     * refused by the service itself, so a crafted request can't forward what
     * the edition never would.
     */
    public function actionResendIntegration(): Response
    {
        $this->requirePostRequest();
        $this->requireAcceptsJson();
        $this->requirePermission('formable:saveForms');

        $integrations = Plugin::getInstance()->getIntegrations();
        $result = $integrations->resend((int)$this->request->getRequiredBodyParam('logId'));

        return $this->asResendResult(
            $result,
            $integrations->getRecentLogsForForm((int)$this->request->getRequiredBodyParam('formId')),
        );
    }

    /**
     * @param array<int, array<string, mixed>> $log
     */
    private function asResendResult(ResendResult $result, array $log): Response
    {
        return $this->asJson([
            'success' => $result->success,
            'message' => $result->message,
            'log' => $log,
        ]);
    }

    /**
     * Renders the builder's current working layout - saved or not - as a bare
     * standalone document, the way it will actually look on the front end.
     *
     * Opened by the builder in a new tab via a plain POST `<form>`, not
     * `fetch()` - the layout can be large, and posting through a real form is
     * what lets the browser open the tab synchronously in the click handler
     * without a pop-up blocker stepping in. The posted `pages`/`settings` are
     * built into a synthetic, unsaved `Form` (handle `formablePreviewAuthor`,
     * distinct from the devMode QA gallery's `formablePreview` -
     * {@see \bytesof\formable\controllers\PreviewController} - so the two can
     * never collide) saved inside a transaction rolled back before the
     * response is sent, the same technique that controller's `actionFrame()`
     * uses. Reachability here is `formable:saveForms`, the same permission
     * every other builder endpoint requires, rather than `devMode` - see
     * [[0076]]. The posted `translations` and an optional `siteUid` let the
     * preview show a site's overrides before they're ever saved - see
     * [[0086]].
     */
    public function actionPreview(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requirePermission('formable:saveForms');

        $plugin = Plugin::getInstance();
        $pages = $plugin->getLayout()->normalizePages($this->arrayParam('pages'));

        $settings = new FormSettings();
        $settings->setAttributes($this->arrayParam('settings'), false);

        // Unlike a saved form, this settings model never runs actionSave()'s
        // validation - so a hand-rolled post naming a path outside the
        // templates directory would otherwise reach Rendering's site-mode
        // template lookup unfiltered. Clearing it on failure falls back to
        // the plugin's own templates, same as an unset override does.
        if (!$settings->validate(['templateOverridePath'])) {
            $settings->templateOverridePath = '';
        }

        $title = (string)$this->request->getBodyParam('title', '');

        $form = new Form();
        $form->title = $title !== '' ? $title : Craft::t('formable', 'Untitled form');
        $form->handle = 'formablePreviewAuthor';
        $form->enabled = true;
        $form->setPages($pages);
        $form->setSettings($settings->toArray());
        $form->setTranslations($this->arrayParam('translations'));

        $pageCount = count($pages);
        $page = (int)$this->request->getBodyParam('page', 0);
        $page = $pageCount > 0 ? max(0, min($page, $pageCount - 1)) : 0;

        // A site switch for the render only, restored before this action
        // returns - the preview's own site selector is a one-request
        // convenience, not something that should leak into the rest of this
        // CP request. Gated on Pro the same way Translations itself is: a
        // Lite request naming a site simply previews the (untranslated)
        // base strings instead.
        $sites = Craft::$app->getSites();
        $originalSite = $sites->getCurrentSite();
        $previewSite = null;

        if ($plugin->isPro()) {
            $siteUid = $this->request->getBodyParam('siteUid');
            $previewSite = is_string($siteUid) && $siteUid !== '' ? $sites->getSiteByUid($siteUid) : null;
        }

        if ($previewSite !== null) {
            $sites->setCurrentSite($previewSite);
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // Validation is deliberately skipped: an in-progress layout is
            // routinely incomplete (a blank handle, a duplicate one), and the
            // whole point of a preview is to see it before it's savable, not
            // to gate the preview on the rules actionSave() enforces. Nothing
            // saved here outlives the rollback below.
            //
            // Saved through Elements directly, not Forms::saveForm() - this
            // element never outlives the transaction, so it has no business
            // in the search index. Forms::saveForm() doesn't expose
            // updateSearchIndex, so it's bypassed here rather than widened
            // for one caller.
            if (!Craft::$app->getElements()->saveElement($form, false, true, false)) {
                throw new ServerErrorHttpException('Could not render the preview.');
            }

            $html = Craft::$app->getView()->renderPageTemplate('formable/forms/_preview', [
                'form' => $form,
                'page' => $page,
                'pageCount' => $pageCount,
                'pagesJson' => Json::encode($pages),
                'settingsJson' => Json::encode($settings->toArray()),
                'translationsJson' => Json::encode($form->getTranslations()),
                'currentSiteUid' => ($previewSite ?? $originalSite)->uid,
            ], View::TEMPLATE_MODE_CP);
        } finally {
            $transaction->rollBack();

            if ($previewSite !== null) {
                $sites->setCurrentSite($originalSite);
            }
        }

        return $this->asRaw($html);
    }

    public function actionDelete(): Response
    {
        $this->requirePostRequest();
        $this->requirePermission('formable:deleteForms');

        $formId = (int)$this->request->getRequiredBodyParam('formId');
        $form = Plugin::getInstance()->getForms()->getFormById($formId);

        if ($form === null) {
            throw new NotFoundHttpException('Form not found');
        }

        if (!Plugin::getInstance()->getForms()->deleteForm($form)) {
            throw new ForbiddenHttpException('Unable to delete form');
        }

        $this->setSuccessFlash(Craft::t('formable', 'Form deleted.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Everything the builder needs to render without asking the server again:
     * the form itself, the field palette (types, icons and settings schemas),
     * and the CP resources fields can point at.
     *
     * @return array<string, mixed>
     */
    private function builderConfig(Form $form, bool $isNew): array
    {
        $plugin = Plugin::getInstance();

        return [
            'form' => [
                'id' => $form->id,
                'title' => (string)$form->title,
                'handle' => (string)$form->handle,
                'enabled' => $form->enabled,
                'defaultStatusId' => $form->defaultStatusId,
                'pages' => $form->getPages(),
                'settings' => $form->getFormSettings()->toArray(),
                'translations' => $form->getTranslations(),
            ],
            'isNew' => $isNew,
            'palette' => $plugin->getFields()->getPaletteDefinition(),
            'groupLabels' => [
                'standard' => Craft::t('formable', 'Standard'),
                'choice' => Craft::t('formable', 'Choice'),
                'advanced' => Craft::t('formable', 'Advanced'),
                'layout' => Craft::t('formable', 'Layout'),
                'relations' => Craft::t('formable', 'Relations'),
            ],
            'formSettingsSchema' => FormSettings::getSettingsSchema(),
            'conditions' => [
                'operators' => ConditionRule::operatorDefinitions(),
                'actions' => [
                    ['value' => ConditionSet::ACTION_SHOW, 'label' => Craft::t('formable', 'Show')],
                    ['value' => ConditionSet::ACTION_HIDE, 'label' => Craft::t('formable', 'Hide')],
                ],
                // A notification and an integration store the same show/hide
                // pair, but nothing is being shown - the set decides whether
                // the thing is sent at all, so it says so in the author's words.
                'notificationActions' => [
                    ['value' => ConditionSet::ACTION_SHOW, 'label' => Craft::t('formable', 'Send')],
                    ['value' => ConditionSet::ACTION_HIDE, 'label' => Craft::t('formable', 'Don’t send')],
                ],
                'integrationActions' => [
                    ['value' => ConditionSet::ACTION_SHOW, 'label' => Craft::t('formable', 'Forward')],
                    ['value' => ConditionSet::ACTION_HIDE, 'label' => Craft::t('formable', 'Don’t forward')],
                ],
                'matches' => [
                    ['value' => ConditionSet::MATCH_ALL, 'label' => Craft::t('formable', 'all')],
                    ['value' => ConditionSet::MATCH_ANY, 'label' => Craft::t('formable', 'any')],
                ],
            ],
            'statuses' => array_map(
                static fn($status): array => [
                    'value' => $status->id,
                    'label' => $status->name,
                ],
                $plugin->getStatuses()->getAllStatuses(),
            ),
            'volumes' => array_map(
                static fn($volume): array => [
                    'value' => $volume->uid,
                    'label' => $volume->name,
                ],
                Craft::$app->getVolumes()->getAllVolumes(),
            ),
            'elementSources' => $this->elementSources(),
            'maxFieldsPerRow' => Layout::MAX_FIELDS_PER_ROW,
            'schemaVersion' => $plugin->schemaVersion,
            'notifications' => $this->formNotifications($form),
            'emailTokens' => $this->emailTokens($form),
            'notificationDefaults' => $this->notificationDefaults(),
            'notificationLog' => $form->id !== null
                ? $plugin->getNotifications()->getRecentLogs($form->id)
                : [],
            'isPro' => $plugin->isPro(),
            'upgradeUrl' => $plugin->getUpgradeUrl() ?? '',
            'sites' => array_map(
                static fn($site): array => [
                    'uid' => $site->uid,
                    'name' => $site->name,
                    'handle' => $site->handle,
                ],
                Craft::$app->getSites()->getAllSites(),
            ),
            'integrations' => $this->availableIntegrations(),
            'integrationLog' => $form->id !== null
                ? $plugin->getIntegrations()->getRecentLogsForForm($form->id)
                : [],
            'integrationsUrl' => $plugin->getIntegrationsUrl() ?? '',
            'actions' => [
                'save' => 'formable/forms/save',
                'submissionCount' => 'formable/forms/submission-count',
                'testNotification' => 'formable/forms/test-notification',
                'resendNotification' => 'formable/forms/resend-notification',
                'resendIntegration' => 'formable/forms/resend-integration',
                'preview' => 'formable/forms/preview',
            ],
            'redirectUrl' => UrlHelper::cpUrl('formable/forms'),
        ];
    }

    /**
     * The enabled global integrations a form can switch on, each with the target
     * fields it maps onto - everything the Integrations tab needs to render the
     * per-form mapping without asking the server again.
     *
     * @return array<int, array<string, mixed>>
     */
    private function availableIntegrations(): array
    {
        $service = Plugin::getInstance()->getIntegrations();

        return array_map(
            static function($record) use ($service): array {
                $provider = $service->createIntegration($record);

                return [
                    'handle' => (string)$record->handle,
                    'name' => (string)$record->name,
                    'type' => (string)$record->type,
                    'category' => $provider !== null ? $provider::category() : '',
                    'mappableFields' => $provider !== null ? $provider->getMappableFields() : [],
                ];
            },
            $service->getEnabledIntegrations(),
        );
    }

    /**
     * A saved form's notifications, in the stored/builder shape.
     *
     * @return array<int, array<string, mixed>>
     */
    private function formNotifications(Form $form): array
    {
        if ($form->id === null) {
            return [];
        }

        return array_map(
            static fn(Notification $notification): array => $notification->toConfig(),
            Plugin::getInstance()->getNotifications()->getFormNotifications($form->id),
        );
    }

    /**
     * The tokens the notification editor offers - one per value field, plus a
     * couple of form-level conveniences.
     *
     * @return array<int, array<string, string>>
     */
    private function emailTokens(Form $form): array
    {
        $tokens = [];

        foreach (Plugin::getInstance()->getFields()->getValueFieldsFromLayout($form->getPages()) as $handle => $field) {
            $tokens[] = [
                'token' => '{' . $handle . '}',
                'label' => $field->label !== '' ? $field->label : $handle,
            ];
        }

        $tokens[] = ['token' => '{formName}', 'label' => Craft::t('formable', 'Form name')];
        $tokens[] = ['token' => '{{ allFields }}', 'label' => Craft::t('formable', 'All fields')];

        return $tokens;
    }

    /**
     * Defaults the editor pre-fills a new notification with, from the system
     * mail settings.
     *
     * @return array<string, string>
     */
    private function notificationDefaults(): array
    {
        $settings = App::mailSettings();

        return [
            'fromName' => (string)App::parseEnv($settings->fromName),
            'fromEmail' => (string)App::parseEnv($settings->fromEmail),
            'testTo' => Craft::$app->getUser()->getIdentity()->email ?? '',
        ];
    }

    /**
     * Element sources per element type, for relation fields' source pickers.
     *
     * @return array<string, array<int, array<string, string>>>
     */
    private function elementSources(): array
    {
        $sources = [];

        foreach ([Entry::class, Category::class, Asset::class, User::class] as $elementType) {
            $sources[$elementType] = array_values(array_filter(array_map(
                static function(array $source): ?array {
                    // Skip headings and dividers - only real sources are pickable.
                    if (($source['type'] ?? null) !== ElementSources::TYPE_NATIVE
                        && ($source['type'] ?? null) !== ElementSources::TYPE_CUSTOM) {
                        return null;
                    }

                    return [
                        'value' => (string)($source['key'] ?? ''),
                        'label' => (string)($source['label'] ?? ''),
                    ];
                },
                Craft::$app->getElementSources()->getSources($elementType, ElementSources::CONTEXT_INDEX),
            )));
        }

        return $sources;
    }

    /**
     * A body param that must be an array - the builder posts JSON, but a
     * hand-rolled request could send anything.
     *
     * @return array<string, mixed>
     */
    private function arrayParam(string $name): array
    {
        $value = $this->request->getBodyParam($name);

        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        return is_array($value) ? $value : [];
    }

    /**
     * A body param that must be a list - the builder posts an array of
     * notifications, but a hand-rolled request could send anything.
     *
     * @return array<int, mixed>
     */
    private function listParam(string $name): array
    {
        $value = $this->request->getBodyParam($name);

        if (is_string($value)) {
            $value = Json::decodeIfJson($value);
        }

        return is_array($value) ? array_values($value) : [];
    }
}
