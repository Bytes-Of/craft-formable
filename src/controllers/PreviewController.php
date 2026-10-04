<?php

declare(strict_types=1);

namespace bytesof\formable\controllers;

use bytesof\formable\dev\PreviewScenarios;
use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\models\FormSettings;
use bytesof\formable\models\Settings;
use bytesof\formable\Plugin;
use bytesof\formable\services\Rendering;
use Craft;
use craft\web\Controller;
use craft\web\View;
use yii\web\NotFoundHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

/**
 * The devMode-only preview gallery: every field type, every field state and
 * every form-level config, in both colour schemes and at each theme level.
 * See [[0034-a-devmode-only-preview-gallery]] for why this earns its place in a commercial plugin.
 *
 * Gated hard rather than hidden - {@see beforeAction()} 404s outright unless
 * `devMode` is on, and {@see \bytesof\formable\Plugin} only ever registers the
 * routes that reach here under the same check. A production install has
 * neither the controller answering nor a URL rule that could route to it.
 *
 * @internal
 */
final class PreviewController extends Controller
{
    private const SCHEMES = ['light', 'dark', 'system'];

    private const THEMES = [
        Settings::THEME_LEVEL_NONE,
        Settings::THEME_LEVEL_CONTRACT,
        Settings::THEME_LEVEL_RESET,
        Settings::THEME_LEVEL_DEFAULT,
    ];

    private const DENSITIES = [
        FormSettings::DENSITY_COMFORTABLE,
        FormSettings::DENSITY_COMPACT,
    ];

    /**
     * Frame-level presentation toggles ([[frontend-embeddability-review]]
     * chunk 1) - how the bare document around the form is built, not a
     * `renderForm()` option. `full` keeps today's full-bleed behaviour.
     */
    private const WIDTHS = ['full', '280', '400', '600', '960'];

    /**
     * Small local CSS fixtures the frame can inject, approximating the
     * specific rules each real library is known to break Formable's
     * defaults with - not the libraries themselves (chunk 1, step 2).
     */
    private const HOSTS = ['none', 'tailwind-v3', 'tailwind-v4', 'bootstrap5', 'prose', 'font-62'];

    private const DIRS = ['ltr', 'rtl'];

    private const CHROMES = ['none', 'dialog', 'sidebar', 'flex-column', 'grid'];

    /**
     * Whether the frame's own page background is derived from the form's
     * `--formable-surface-raised` token (today's default) or hardcoded
     * white, simulating a host page that paints its own background
     * regardless of what the embedded form is theming itself - the only way
     * the `embed-light-host-dark-os` scenario can surface H2 rather than
     * mask it.
     */
    private const PAGE_BACKGROUNDS = ['derived', 'white'];

    /** @var array<int, string>|int|bool */
    protected array|int|bool $allowAnonymous = ['index', 'frame'];

    public function beforeAction($action): bool
    {
        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            throw new NotFoundHttpException();
        }

        return parent::beforeAction($action);
    }

    /**
     * The gallery shell: a scenario index, grouped in display order. Each
     * scenario links out to its own {@see actionFrame()} URL - the shell never
     * renders a form itself, so it needs no transaction of its own.
     */
    public function actionIndex(): Response
    {
        $groups = [];

        foreach (PreviewScenarios::scenarios() as $scenario) {
            $groups[$scenario['group']][] = [
                'key' => $scenario['key'],
                'label' => $scenario['label'],
            ];
        }

        return $this->renderTemplate('formable/preview/_index', [
            'groups' => $groups,
        ], View::TEMPLATE_MODE_SITE);
    }

    /**
     * One scenario, rendered as a bare standalone document - the frame
     * Playwright will eventually point at directly.
     *
     * The scenario's form is saved inside a transaction rolled back before the
     * response is sent: {@see Submission::getForm()} and
     * {@see \bytesof\formable\services\PageFlow::getPageSequence()} both resolve
     * an unsaved form's id to nothing, so the prefilled, error, progress and
     * review scenarios would silently render as a single page with no values.
     * Saving for the length of the render - the same shape
     * `AccessibilityFixturesTest` uses - fixes that without anything
     * persisting past the request.
     */
    public function actionFrame(): Response
    {
        $scenario = $this->resolveScenario();
        /** @var Form $form */
        $form = $scenario['form'];
        /** @var array<string, mixed> $scenarioOptions */
        $scenarioOptions = $scenario['options'];

        $preview = $this->resolvePreviewDefaults($scenario);
        $scheme = $preview['scheme'];
        $density = $this->queryValue('density', self::DENSITIES, FormSettings::DENSITY_COMFORTABLE);
        $theme = $this->queryValue(
            'theme',
            self::THEMES,
            is_string($scenarioOptions['theme'] ?? null) ? $scenarioOptions['theme'] : Settings::THEME_LEVEL_DEFAULT,
        );

        // `FormSettings::$density` (chunk 5.3) landed after this plan's Design
        // section was written, so the frame drives the real setting rather
        // than prototyping it with inline `--formable-control-*` overrides.
        $rawSettings = $form->getSettings();
        $rawSettings['density'] = $density;
        $form->setSettings($rawSettings);

        $options = $scenarioOptions;
        $options['theme'] = $theme;

        // A form renders pinned light by default (0071) and a pinned form
        // ignores its ancestor's scheme, so the `<html>` attribute alone would
        // show a light form on a dark page. The form gets the scheme as its
        // own setting, the way a site renders it; the attribute stays for the
        // frame's chrome.
        if ($scheme !== 'system' && !PreviewScenarios::pinsScheme($scenario)) {
            $options['colorScheme'] = $scheme;
        }

        if ($scenario['flashSuccess'] ?? false) {
            $this->flashSuccess($form);
        }

        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            if (!Plugin::getInstance()->getForms()->saveForm($form)) {
                throw new ServerErrorHttpException(
                    'Could not save the preview scenario’s form: ' . json_encode($form->getErrors(), JSON_THROW_ON_ERROR),
                );
            }

            // The scenario's own submission (error, prefilled) has to be
            // resolvable against the form actually being rendered -
            // `Rendering::resolveSubmission()` only honours `options['submission']`
            // when its `formId` matches, so without this it's silently
            // discarded in favour of an empty one.
            if (($options['submission'] ?? null) instanceof Submission) {
                $options['submission']->formId = $form->id;
            }

            // `prefillValues` (not a ready-made `Submission`, unlike the
            // error scenario above): `Submission::setValue()` resolves the
            // field it's writing to through `getForm()`, which needs a
            // `formId` the catalog can't supply before this point - the
            // scenario's form is unsaved when `PreviewScenarios` builds it.
            // Only safe to call now that the form above has an id.
            if (is_array($options['prefillValues'] ?? null)) {
                $submission = new Submission();
                $submission->formId = $form->id;
                $submission->setValues($options['prefillValues']);
                $options['submission'] = $submission;
            }

            // `renderPageTemplate()`, not the plain `renderTemplate()` - only
            // it wraps the render in `beginPage()`/`endPage()`, which is what
            // turns the frame's `head()`/`beginBody()`/`endBody()` calls into
            // the actual `<link>`/`<script>` tags for the assets `renderForm()`
            // registers below. The plain render would leave those as literal,
            // unresolved placeholder strings.
            $html = Craft::$app->getView()->renderPageTemplate('formable/preview/_frame', [
                'form' => $form,
                'options' => $options,
                'scenario' => $scenario,
                'scheme' => $scheme,
                'theme' => $theme,
                'density' => $density,
                'width' => $preview['width'],
                'host' => $preview['host'],
                'dir' => $preview['dir'],
                'chrome' => $preview['chrome'],
                'pageBackground' => $preview['pageBackground'],
                'synthesizeDisabled' => $scenario['synthesizeDisabled'] ?? false,
            ], View::TEMPLATE_MODE_SITE);
        } finally {
            $transaction->rollBack();
        }

        $this->response->data = $html;

        return $this->response;
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveScenario(): array
    {
        $key = $this->request->getQueryParam('scenario');

        if (!is_string($key) || $key === '') {
            throw new NotFoundHttpException('No scenario specified.');
        }

        foreach (PreviewScenarios::scenarios() as $scenario) {
            if ($scenario['key'] === $key) {
                return $scenario;
            }
        }

        throw new NotFoundHttpException('Unknown preview scenario.');
    }

    /**
     * Sets the success flash the way a real completed submission would, so
     * `messages-success` shows the form's actual configured message.
     */
    private function flashSuccess(Form $form): void
    {
        $rendering = Plugin::getInstance()->getRendering();
        $settings = $form->getFormSettings();
        $message = $rendering->renderMessage($settings->successMessage, $form);

        Craft::$app->getSession()->setFlash(Rendering::SUCCESS_FLASH_PREFIX . (string)$form->handle, $message);

        if ($settings->successDetails !== '') {
            $details = $rendering->renderMessage($settings->successDetails, $form);

            Craft::$app->getSession()->setFlash(
                Rendering::SUCCESS_DETAILS_FLASH_PREFIX . (string)$form->handle,
                $details,
            );
        }
    }

    /**
     * A query param, accepted only when it's one of `$allowed` - every frame
     * parameter is addressable, but a stray or hand-edited value falls back
     * to `$default` rather than reaching the renderer.
     *
     * @param array<int, string> $allowed
     */
    private function queryValue(string $name, array $allowed, string $default): string
    {
        $value = $this->request->getQueryParam($name);

        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * `scheme`, `width`, `host`, `dir`, `chrome` and `pageBackground` - the
     * toggles that shape the bare document around the form rather than the
     * form itself. Each one resolves a query param over the scenario's own
     * `previewDefaults` (so `embed-rtl` opens already showing `dir=rtl`,
     * for instance) over a hardcoded default - the same precedence `theme`
     * already uses against `options['theme']`, generalised for toggles that
     * have nothing to do with `renderForm()`.
     *
     * @param array<string, mixed> $scenario
     * @return array<string, string>
     */
    private function resolvePreviewDefaults(array $scenario): array
    {
        $previewDefaults = is_array($scenario['previewDefaults'] ?? null) ? $scenario['previewDefaults'] : [];

        $resolve = function(string $name, array $allowed, string $hardDefault) use ($previewDefaults): string {
            $scenarioDefault = $previewDefaults[$name] ?? null;
            $default = is_string($scenarioDefault) && in_array($scenarioDefault, $allowed, true)
                ? $scenarioDefault
                : $hardDefault;

            return $this->queryValue($name, $allowed, $default);
        };

        // A pinned form paints no background of its own (0071): it trusts the
        // page around it to be in its scheme. So the frame starts in the
        // scheme the form is pinned to, the way a site built for it would,
        // or a pinned-dark form shows light text on a light page.
        $pinned = $scenario['options']['colorScheme'] ?? null;
        $schemeDefault = in_array($pinned, ['light', 'dark'], true) ? $pinned : 'system';

        return [
            'scheme' => $resolve('scheme', self::SCHEMES, $schemeDefault),
            'width' => $resolve('width', self::WIDTHS, 'full'),
            'host' => $resolve('host', self::HOSTS, 'none'),
            'dir' => $resolve('dir', self::DIRS, 'ltr'),
            'chrome' => $resolve('chrome', self::CHROMES, 'none'),
            'pageBackground' => $resolve('pageBackground', self::PAGE_BACKGROUNDS, 'derived'),
        ];
    }
}
