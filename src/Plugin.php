<?php

declare(strict_types=1);

namespace bytesof\formable;

use bytesof\formable\db\Table;
use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\events\SubmissionEvent;
use bytesof\formable\gql\Registrar as GqlRegistrar;
use bytesof\formable\helpers\DeliveryLog;
use bytesof\formable\jobs\PurgeSubmissions;
use bytesof\formable\models\Settings;
use bytesof\formable\services\Captchas;
use bytesof\formable\services\Conditions;
use bytesof\formable\services\Digest;
use bytesof\formable\services\Fields;
use bytesof\formable\services\Forms;
use bytesof\formable\services\FormTransfer;
use bytesof\formable\services\Integrations;
use bytesof\formable\services\Layout;
use bytesof\formable\services\Notifications;
use bytesof\formable\services\PageFlow;
use bytesof\formable\services\Presets;
use bytesof\formable\services\Progress;
use bytesof\formable\services\Rendering;
use bytesof\formable\services\Spam;
use bytesof\formable\services\Statuses;
use bytesof\formable\services\SubmissionGate;
use bytesof\formable\services\Submissions;
use bytesof\formable\services\Translations;
use bytesof\formable\services\Uploads;
use bytesof\formable\variables\FormableVariable;
use bytesof\formable\widgets\RecentSubmissions;
use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\RebuildConfigEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\UrlHelper;
use craft\services\Dashboard;
use craft\services\Elements;
use craft\services\Gc;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use yii\base\Event;

/**
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 * @property-read Captchas $captchas
 * @property-read Conditions $conditions
 * @property-read Digest $digest
 * @property-read Fields $fields
 * @property-read Forms $forms
 * @property-read FormTransfer $formTransfer
 * @property-read Integrations $integrations
 * @property-read Layout $layout
 * @property-read Notifications $notifications
 * @property-read PageFlow $pageFlow
 * @property-read Presets $presets
 * @property-read Progress $progress
 * @property-read Rendering $rendering
 * @property-read Spam $spam
 * @property-read Statuses $statuses
 * @property-read SubmissionGate $submissionGate
 * @property-read Submissions $submissions
 * @property-read Translations $translations
 * @property-read Uploads $uploads
 *
 * @internal
 */
final class Plugin extends BasePlugin
{
    /** @api */
    public const EDITION_LITE = 'lite';
    /** @api */
    public const EDITION_PRO = 'pro';

    public string $schemaVersion = '1.11.0';
    public bool $hasCpSection = true;
    public bool $hasCpSettings = true;

    /**
     * @return array<string, mixed>
     */
    public static function config(): array
    {
        return [
            'components' => [
                'captchas' => Captchas::class,
                'conditions' => Conditions::class,
                'digest' => Digest::class,
                'fields' => Fields::class,
                'forms' => Forms::class,
                'formTransfer' => FormTransfer::class,
                'integrations' => Integrations::class,
                'layout' => Layout::class,
                'notifications' => Notifications::class,
                'pageFlow' => PageFlow::class,
                'presets' => Presets::class,
                'progress' => Progress::class,
                'rendering' => Rendering::class,
                'spam' => Spam::class,
                'statuses' => Statuses::class,
                'submissionGate' => SubmissionGate::class,
                'submissions' => Submissions::class,
                'translations' => Translations::class,
                'uploads' => Uploads::class,
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

    /**
     * Whether the active edition is Pro.
     *
     * Single choke-point for every Pro gate: call this instead of
     * `is(self::EDITION_PRO)` so gating polarity and readability stay uniform.
     *
     * @api
     */
    public function isPro(): bool
    {
        return $this->is(self::EDITION_PRO);
    }

    /**
     * Where a Lite upgrade prompt sends the current user, or null when they
     * could not open it.
     *
     * The editions section lives on Craft's own plugin-settings screen, which
     * Craft keeps admin-only whatever this plugin grants. A form author with
     * every Formable permission would otherwise follow the prompt to a 403, so
     * they are told to ask an administrator instead.
     *
     * Craft renders plugin settings inside its `settings` input namespace, so
     * the section's `formable-editions` id reaches the page prefixed.
     */
    public function getUpgradeUrl(): ?string
    {
        if (!Craft::$app->getUser()->getIsAdmin()) {
            return null;
        }

        return UrlHelper::cpUrl('settings/plugins/formable') . '#settings-formable-editions';
    }

    /**
     * The integration settings screen, or null when the current user may not
     * open it - the builder's empty integrations state points there.
     */
    public function getIntegrationsUrl(): ?string
    {
        if (!Craft::$app->getUser()->checkPermission('formable:manageIntegrations')) {
            return null;
        }

        return UrlHelper::cpUrl('formable/integrations');
    }

    public function init(): void
    {
        parent::init();

        Craft::setAlias('@formable', $this->getBasePath());

        $this->attachProjectConfigHandlers();
        $this->attachEventHandlers();
    }

    public function getCaptchas(): Captchas
    {
        /** @var Captchas */
        return $this->get('captchas');
    }

    public function getConditions(): Conditions
    {
        /** @var Conditions */
        return $this->get('conditions');
    }

    public function getDigest(): Digest
    {
        /** @var Digest */
        return $this->get('digest');
    }

    public function getFields(): Fields
    {
        /** @var Fields */
        return $this->get('fields');
    }

    public function getForms(): Forms
    {
        /** @var Forms */
        return $this->get('forms');
    }

    public function getFormTransfer(): FormTransfer
    {
        /** @var FormTransfer */
        return $this->get('formTransfer');
    }

    public function getIntegrations(): Integrations
    {
        /** @var Integrations */
        return $this->get('integrations');
    }

    public function getLayout(): Layout
    {
        /** @var Layout */
        return $this->get('layout');
    }

    public function getNotifications(): Notifications
    {
        /** @var Notifications */
        return $this->get('notifications');
    }

    public function getPageFlow(): PageFlow
    {
        /** @var PageFlow */
        return $this->get('pageFlow');
    }

    public function getPresets(): Presets
    {
        /** @var Presets */
        return $this->get('presets');
    }

    public function getProgress(): Progress
    {
        /** @var Progress */
        return $this->get('progress');
    }

    public function getRendering(): Rendering
    {
        /** @var Rendering */
        return $this->get('rendering');
    }

    public function getSpam(): Spam
    {
        /** @var Spam */
        return $this->get('spam');
    }

    public function getStatuses(): Statuses
    {
        /** @var Statuses */
        return $this->get('statuses');
    }

    public function getSubmissionGate(): SubmissionGate
    {
        /** @var SubmissionGate */
        return $this->get('submissionGate');
    }

    public function getSubmissions(): Submissions
    {
        /** @var Submissions */
        return $this->get('submissions');
    }

    public function getTranslations(): Translations
    {
        /** @var Translations */
        return $this->get('translations');
    }

    public function getUploads(): Uploads
    {
        /** @var Uploads */
        return $this->get('uploads');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $navItem = parent::getCpNavItem();

        if ($navItem === null) {
            return null;
        }

        $user = Craft::$app->getUser();
        $subnav = [];

        if ($user->checkPermission('formable:viewForms')) {
            $subnav['forms'] = [
                'label' => Craft::t('formable', 'Forms'),
                'url' => 'formable/forms',
            ];
        }

        if ($user->checkPermission('formable:viewSubmissions')) {
            $subnav['submissions'] = [
                'label' => Craft::t('formable', 'Submissions'),
                'url' => 'formable/submissions',
            ];
        }

        if ($user->checkPermission('formable:manageIntegrations')) {
            $subnav['integrations'] = [
                'label' => Craft::t('formable', 'Integrations'),
                'url' => 'formable/integrations',
            ];
        }

        // Statuses are project config, so - like every project-config editor -
        // the screen is hidden wherever admin changes are locked down, on top
        // of the permission check: what is edited here is deployed, not
        // entered, regardless of who is allowed to enter it.
        if (
            $user->checkPermission('formable:manageStatuses')
            && Craft::$app->getConfig()->getGeneral()->allowAdminChanges
        ) {
            $subnav['statuses'] = [
                'label' => Craft::t('formable', 'Statuses'),
                'url' => 'formable/statuses',
            ];
        }

        // Global settings hold captcha/digest/API-key config and render through
        // Craft's own settings/plugins/<handle> route, which hardwires
        // requireAdmin() - see PluginsController::actionEditPluginSettings().
        // That bar can't be delegated by a plugin-registered permission, so
        // this item stays admin-only rather than silently 403ing a non-admin
        // it led here.
        if ($user->getIsAdmin() && Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $subnav['settings'] = [
                'label' => Craft::t('app', 'Settings'),
                'url' => 'settings/plugins/formable',
            ];
        }

        if ($subnav === []) {
            return null;
        }

        $navItem['subnav'] = $subnav;

        return $navItem;
    }

    protected function createSettingsModel(): Model
    {
        return Craft::createObject(Settings::class);
    }

    protected function settingsHtml(): string
    {
        return Craft::$app->getView()->renderTemplate('formable/_settings', [
            'settings' => $this->getSettings(),
            'isPro' => $this->isPro(),
        ]);
    }

    /**
     * Wires the project-config paths Formable owns to the services that keep
     * their read models in step.
     *
     * Separate from {@see attachEventHandlers()} and registered unconditionally:
     * config is applied on console requests and site requests as readily as in
     * the control panel, so none of this may sit behind a request-type check.
     */
    private function attachProjectConfigHandlers(): void
    {
        $statuses = $this->getStatuses();
        $path = Statuses::CONFIG_KEY . '.{uid}';

        Craft::$app->getProjectConfig()
            ->onAdd($path, [$statuses, 'handleChangedStatus'])
            ->onUpdate($path, [$statuses, 'handleChangedStatus'])
            ->onRemove($path, [$statuses, 'handleDeletedStatus']);

        // Without this, `project-config/rebuild` would rebuild every other
        // config path from the database and leave Formable's carrying whatever
        // it happened to hold.
        Event::on(
            ProjectConfig::class,
            ProjectConfig::EVENT_REBUILD,
            static function(RebuildConfigEvent $event): void {
                $event->config['formable']['statuses'] = Plugin::getInstance()
                    ->getStatuses()
                    ->getConfigForRebuild();
            },
        );
    }

    private function attachEventHandlers(): void
    {
        Event::on(
            Elements::class,
            Elements::EVENT_REGISTER_ELEMENT_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = Form::class;
                $event->types[] = Submission::class;
            },
        );

        // Abandoned multi-page and save-and-resume submissions expire on their
        // own; reaping them rides along with Craft's own garbage collection so
        // it needs no separate schedule.
        Event::on(
            Gc::class,
            Gc::EVENT_RUN,
            static function(): void {
                // Expired in-progress submissions, and GDPR retention for
                // completed ones (Pro; a no-op in Lite). Rides Craft's GC
                // rather than a schedule of its own, but queued: GC runs on a
                // visitor's request, and a retention backlog can be six figures.
                Craft::$app->getQueue()->push(new PurgeSubmissions());
                // A submission whose own `elements`/`searchindex` rows outlived
                // its `formable_submissions` row - the FK cascade from a
                // hard-deleted form removes the latter without touching the
                // former. Form::afterDelete() now trashes submissions with the
                // form, so this is a backstop for data from before that fix.
                Craft::$app->getGc()->deletePartialElements(Submission::class, Table::SUBMISSIONS, 'id');
                Plugin::getInstance()->getUploads()->deleteOrphanedUploads();
                DeliveryLog::purgeOlderThan(Plugin::getInstance()->getSettings()->deliveryLogRetentionDays);
            },
        );

        // Notifications and integrations both hang off the end of the submission
        // pipeline - the one event that fires for a completed submission whether
        // it was stored or not, and which never fires for spam, so a notify-only
        // form (storeSubmissions off) still sends and a flagged one forwards
        // nowhere.
        Event::on(
            Submissions::class,
            Submissions::EVENT_AFTER_SUBMIT,
            static function(SubmissionEvent $event): void {
                $plugin = Plugin::getInstance();
                $plugin->getNotifications()->sendForSubmission($event->submission);
                $plugin->getIntegrations()->sendForSubmission($event->submission);
            },
        );

        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            static function(RegisterUserPermissionsEvent $event): void {
                $event->permissions[] = [
                    'heading' => Craft::t('formable', 'Formable'),
                    'permissions' => [
                        'formable:viewForms' => [
                            'label' => Craft::t('formable', 'View forms'),
                            'nested' => [
                                'formable:saveForms' => [
                                    'label' => Craft::t('formable', 'Create and edit forms'),
                                ],
                                'formable:deleteForms' => [
                                    'label' => Craft::t('formable', 'Delete forms'),
                                ],
                            ],
                        ],
                        'formable:viewSubmissions' => [
                            'label' => Craft::t('formable', 'View submissions'),
                            'nested' => [
                                'formable:saveSubmissions' => [
                                    'label' => Craft::t('formable', 'Edit submissions'),
                                ],
                                'formable:deleteSubmissions' => [
                                    'label' => Craft::t('formable', 'Delete submissions'),
                                ],
                                'formable:exportSensitive' => [
                                    'label' => Craft::t('formable', 'Export encrypted field values'),
                                ],
                            ],
                        ],
                        'formable:manageIntegrations' => [
                            'label' => Craft::t('formable', 'Manage integrations'),
                        ],
                        'formable:manageStatuses' => [
                            'label' => Craft::t('formable', 'Manage submission statuses'),
                        ],
                    ],
                ];
            },
        );

        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            static function(RegisterComponentTypesEvent $event): void {
                $event->types[] = RecentSubmissions::class;
            },
        );

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event): void {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('formable', FormableVariable::class);
            },
        );

        // Headless GraphQL support is a Pro feature. Registering it only on Pro
        // means Lite simply has no Formable GraphQL surface, rather than a
        // disabled-but-present one - the graceful way to gate it.
        if ($this->isPro()) {
            GqlRegistrar::register();
        }

        // The front-end template pack has to resolve on site requests too -
        // Craft only registers plugin template roots for the CP by default.
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event): void {
                $event->roots['formable'] = $this->getBasePath() . DIRECTORY_SEPARATOR . 'templates';
            },
        );

        if (Craft::$app->getRequest()->getIsCpRequest()) {
            Event::on(
                UrlManager::class,
                UrlManager::EVENT_REGISTER_CP_URL_RULES,
                static function(RegisterUrlRulesEvent $event): void {
                    $event->rules['formable'] = 'formable/forms/index';
                    $event->rules['formable/forms'] = 'formable/forms/index';
                    // Creating a form opens on a preset picker; choosing one
                    // seeds the builder through forms/create.
                    $event->rules['formable/forms/new'] = 'formable/forms/new';
                    $event->rules['formable/forms/create'] = 'formable/forms/create';
                    $event->rules['formable/forms/<formId:\d+>'] = 'formable/forms/edit';
                    // Submission management lives on a separate controller from
                    // the anonymous front-end one, reached through these tidy
                    // URLs rather than its own controller id.
                    $event->rules['formable/submissions'] = 'formable/manage-submissions/index';
                    $event->rules['formable/submissions/form/<formHandle:[\w\-]+>'] = 'formable/manage-submissions/index';
                    $event->rules['formable/submissions/<submissionId:\d+>'] = 'formable/manage-submissions/edit';
                    // Global integration instances - admin-scoped connection
                    // config, reached through these tidy URLs.
                    $event->rules['formable/integrations'] = 'formable/integrations/index';
                    $event->rules['formable/integrations/new'] = 'formable/integrations/edit';
                    $event->rules['formable/integrations/new/<type:[\w\-]+>'] = 'formable/integrations/edit';
                    $event->rules['formable/integrations/<integrationId:\d+>'] = 'formable/integrations/edit';
                    // Submission statuses - the workflow states, held in
                    // project config.
                    $event->rules['formable/statuses'] = 'formable/statuses/index';
                    $event->rules['formable/statuses/new'] = 'formable/statuses/edit';
                    $event->rules['formable/statuses/<statusId:\d+>'] = 'formable/statuses/edit';
                },
            );
        } else {
            // A friendly resume URL, so the link in a save-and-resume email
            // reads as part of the site rather than an /actions/ endpoint.
            Event::on(
                UrlManager::class,
                UrlManager::EVENT_REGISTER_SITE_URL_RULES,
                static function(RegisterUrlRulesEvent $event): void {
                    $event->rules['formable/resume'] = 'formable/submissions/resume';

                    // The preview gallery is devMode-only - PreviewController
                    // gates hard on the same check, but the rules are left out
                    // entirely on a production install so nothing routes here
                    // even before the controller gets a chance to 404.
                    if (Craft::$app->getConfig()->getGeneral()->devMode) {
                        $event->rules['formable/preview'] = 'formable/preview/index';
                        $event->rules['formable/preview/frame'] = 'formable/preview/frame';
                    }
                },
            );
        }
    }
}
