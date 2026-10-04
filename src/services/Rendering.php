<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\base\FormField;
use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\fields\FileUpload;
use bytesof\formable\models\FieldMap;
use bytesof\formable\models\FormSettings;
use bytesof\formable\models\FormShell;
use bytesof\formable\models\Palette;
use bytesof\formable\models\ResolvedFlow;
use bytesof\formable\models\Settings;
use bytesof\formable\Plugin;
use bytesof\formable\web\assets\frontend\ContractAsset;
use bytesof\formable\web\assets\frontend\FrontendAsset;
use bytesof\formable\web\assets\frontend\ResetAsset;
use bytesof\formable\web\assets\frontend\ThemeAsset;
use Craft;
use craft\helpers\UrlHelper;
use craft\web\View;
use Throwable;
use Twig\Extension\SandboxExtension;
use Twig\Markup;
use yii\base\Component;

/**
 * Renders forms on the front end.
 *
 * Templates resolve in two steps: a per-form (or per-render) override path in
 * the site's own templates directory wins, and anything it doesn't define
 * falls back to the plugin's pack. That means an author can override just
 * `fields/text.twig` without copying the other twenty-two.
 *
 * @internal
 */
final class Rendering extends Component
{
    /**
     * The plugin's template pack, addressed through the `formable` template
     * root registered for site requests.
     */
    public const TEMPLATE_ROOT = 'formable/frontend';

    /**
     * Session flash key for a successful non-AJAX submission, suffixed with
     * the form handle so two forms on one page don't show each other's
     * success message.
     */
    public const SUCCESS_FLASH_PREFIX = 'formable:success:';

    /**
     * Session flash key for the optional success-details line that
     * accompanies {@see SUCCESS_FLASH_PREFIX}, suffixed the same way. A
     * separate key rather than folding into the message flash's shape, since
     * that one is read as a plain string in more than one place.
     */
    public const SUCCESS_DETAILS_FLASH_PREFIX = 'formable:successDetails:';

    /**
     * Session flash key for the confirmation of a non-AJAX save for later,
     * suffixed with the form handle for the same reason as
     * {@see SUCCESS_FLASH_PREFIX}.
     */
    public const SAVED_FLASH_PREFIX = 'formable:saved:';

    /**
     * Failure mode for {@see renderObjectString()}: a string that could not be
     * rendered falls back to its own raw source, tags and all.
     */
    public const ON_ERROR_SOURCE = 'source';

    /**
     * Failure mode for {@see renderObjectString()}: a string that could not be
     * rendered falls back to nothing.
     */
    public const ON_ERROR_EMPTY = 'empty';

    /**
     * Output context for {@see renderObjectString()}: the rendered string is
     * printed as plain text, or somewhere else that already escapes it (Craft's
     * own Twig autoescape, a JS `.textContent` assignment). No transformation.
     */
    public const CONTEXT_TEXT = 'text';

    /**
     * Output context for {@see renderObjectString()}: the rendered string is
     * printed straight into an HTML document - a notification body - with no
     * escaping of its own. See {@see renderObjectString()} for why the
     * escaping this context needs happens before the render, not after.
     */
    public const CONTEXT_HTML = 'html';

    /**
     * Output context for {@see renderObjectString()}: a single mail address or
     * display name, never a list. A literal `\r`/`\n` in the result is always a
     * mistake here, not an author's delimiter, and is stripped - unlike a
     * recipients field, which uses one deliberately (see
     * {@see \bytesof\formable\services\Notifications::resolveAddresses()}) and is
     * left on {@see CONTEXT_TEXT}, relying on the per-address validation it
     * already does after rendering.
     */
    public const CONTEXT_ADDRESS = 'address';

    /**
     * Output context for {@see renderObjectString()}: a redirect URL. A literal
     * `\r`/`\n` is stripped, and a result that resolves to a `javascript:`,
     * `data:` or `vbscript:` URI - never a legitimate redirect target - is
     * discarded.
     */
    public const CONTEXT_URL = 'url';

    /**
     * Whether each override template looked up this request exists, keyed by
     * `"$overridePath/$name"`.
     *
     * A form with an override path calls {@see resolveTemplatePath()} once per
     * field plus once each for `_row`, `_page`, `_step`, `_nav`, `_progress`,
     * `_spam` and `_captcha` - every one a filesystem existence check behind two
     * template-mode switches. The answer can't change within a request, so each
     * name is looked up once. The existence is kept rather than the resolved
     * path because the path a miss falls back to depends on the caller's root.
     *
     * @var array<string, bool>
     */
    private array $overrideExists = [];

    /**
     * Renders a complete form, ready to echo.
     *
     * @param array<string, mixed> $options
     */
    public function renderForm(Form|string|null $form, array $options = []): ?Markup
    {
        $form = $this->resolveForm($form);

        if ($form === null) {
            return null;
        }

        // A form that isn't accepting submissions - disabled, trashed, or
        // outside its schedule window - renders its closed message in place of
        // the fields, so a template can embed a form unconditionally and still
        // do the right thing once it shuts.
        if (!$form->isAcceptingSubmissions()) {
            return $this->renderClosed($form, $options);
        }

        // Resolved before the submission, and passed down through the options:
        // both the shell's hidden input and the progress lookup have to name
        // the same flow, or a page reload can't find where the last one left
        // off.
        $flow = $this->resolveFlow($form, $options);
        $flowId = $flow->id;
        $options['flowId'] = $flowId;

        $submission = $this->resolveSubmission($form, $options);

        if (($options['registerAssets'] ?? true) === true) {
            $this->registerAssets($form, $options);
        }

        $pages = $this->preparePages($form);
        $pageFlow = Plugin::getInstance()->getPageFlow();

        $currentPage = $this->resolveCurrentPage($form, $submission, $options);
        $isReview = $this->resolveReview($form, $submission, $currentPage, $options);

        $palette = $this->resolvePalette($form, $options);
        $paletteDeclarations = $palette->toCssDeclarations();
        $settings = Plugin::getInstance()->getTranslations()->localizeSettings($form);

        $html = $this->renderTemplate($form, 'form', [
            'form' => $form,
            'pages' => $pages,
            'submission' => $submission,
            'settings' => $settings,
            // F8 correction to 0083: the settings passed above are already
            // localized, but `errorMessage` also carries author Twig (`{{ name }}`)
            // - form.twig needs it rendered, not just translated, the same way
            // the AJAX/flash paths already render it in SubmissionsController.
            'errorMessage' => $this->renderMessage($settings->errorMessage, $submission),
            'errors' => $submission->getAllFieldErrors(),
            'values' => $submission->getValues(),
            'successMessage' => $this->formFlash($form, self::SUCCESS_FLASH_PREFIX),
            'successDetails' => $this->formFlash($form, self::SUCCESS_DETAILS_FLASH_PREFIX),
            'savedMessage' => $this->formFlash($form, self::SAVED_FLASH_PREFIX),
            'hasFileUploads' => $this->hasFileUploads($pages),
            'currentPage' => $currentPage,
            'isReview' => $isReview,
            'flowId' => $flowId,
            'flowIsFresh' => $flow->isFresh,
            'tokensUrl' => $this->tokensUrl(),
            'sequence' => $pageFlow->getPageSequence($submission),
            'progress' => $pageFlow->getProgressPosition($submission, $currentPage),
            'isLastPage' => $pageFlow->isLastPage($submission, $currentPage),
            'spam' => Plugin::getInstance()->getSpam()->getFrontEndConfig($form),
            'colorScheme' => $this->resolveColorScheme($form, $options['colorScheme'] ?? null),
            'palette' => $palette,
            'paletteHash' => $paletteDeclarations !== [] ? $this->paletteHash($paletteDeclarations) : null,
            'paletteVarsJson' => $paletteDeclarations !== [] ? json_encode($paletteDeclarations, JSON_THROW_ON_ERROR) : null,
            'headingLevel' => $this->resolveHeadingLevel($options),
            'restartUrl' => $this->resolveRestartUrl($options),
            'options' => $options,
        ], $options);

        return $html !== null ? new Markup($html, Craft::$app->charset) : null;
    }

    /**
     * Renders a form's shell - the success notice, `<form>` tag, hidden
     * inputs and error summary ({@see FormShell::$open}) paired with the
     * captcha, submit button and closing tag ({@see FormShell::$close}) -
     * for an author laying out fields by hand with {@see renderField()}.
     *
     * Reuses {@see renderForm()}'s own resolution, in the same order, but
     * unlike `renderForm()` there is no early return for a closed form: the
     * two templates carry their own `{% if not accepting %}` branch, so the
     * same variables are built either way and the closed message renders
     * inside the shell rather than in place of it.
     *
     * @param array<string, mixed> $options
     */
    public function renderFormShell(Form|string|null $form, array $options = []): ?FormShell
    {
        $form = $this->resolveForm($form);

        if ($form === null) {
            return null;
        }

        $accepting = $form->isAcceptingSubmissions();

        $flow = $this->resolveFlow($form, $options);
        $flowId = $flow->id;
        $options['flowId'] = $flowId;

        $formId = $options['id'] ?? "formable-form-{$form->handle}-{$flowId}";

        $submission = $this->resolveSubmission($form, $options);

        if (($options['registerAssets'] ?? true) === true) {
            $this->registerAssets($form, $options);
        }

        $palette = $this->resolvePalette($form, $options);
        $paletteDeclarations = $palette->toCssDeclarations();
        $settings = Plugin::getInstance()->getTranslations()->localizeSettings($form);

        $pages = $this->preparePages($form);
        $errors = $submission->getAllFieldErrors();
        $hasErrors = $errors !== [] || $submission->hasErrors();

        // Built across every page, not just one - the author may lay fields
        // out from any of them, unlike form.twig's own fieldsByHandle, which
        // only needs the current page.
        $fieldsByHandle = [];

        if ($hasErrors) {
            foreach ($pages as $page) {
                foreach ($page['rows'] as $row) {
                    foreach ($row['fields'] as $field) {
                        $fieldsByHandle[$field->handle] = $field;
                    }
                }
            }
        }

        $templatePath = $this->getTemplatePath($form, $options);
        $spam = Plugin::getInstance()->getSpam()->getFrontEndConfig($form);

        $open = $this->renderTemplate($form, '_shellOpen', [
            'form' => $form,
            'options' => $options,
            'flowId' => $flowId,
            'flowIsFresh' => $flow->isFresh,
            'tokensUrl' => $this->tokensUrl(),
            'formId' => $formId,
            'headingLevel' => $this->resolveHeadingLevel($options),
            'restartUrl' => $this->resolveRestartUrl($options),
            'settings' => $settings,
            'errorMessage' => $this->renderMessage($settings->errorMessage, $submission),
            'errors' => $errors,
            'hasErrors' => $hasErrors,
            'fieldsByHandle' => $fieldsByHandle,
            // renderField() leaves a field on its bare id (decision 0068) -
            // this is what makes the shell's summary anchors match it with
            // no formId threaded through by the author.
            'fieldIdPrefix' => 'formable-',
            'successMessage' => $this->formFlash($form, self::SUCCESS_FLASH_PREFIX),
            'successDetails' => $this->formFlash($form, self::SUCCESS_DETAILS_FLASH_PREFIX),
            'savedMessage' => $this->formFlash($form, self::SAVED_FLASH_PREFIX),
            'colorScheme' => $this->resolveColorScheme($form, $options['colorScheme'] ?? null),
            'palette' => $palette,
            'paletteHash' => $paletteDeclarations !== [] ? $this->paletteHash($paletteDeclarations) : null,
            'paletteVarsJson' => $paletteDeclarations !== [] ? json_encode($paletteDeclarations, JSON_THROW_ON_ERROR) : null,
            'hasFileUploads' => $this->hasFileUploads($pages),
            'isMultiPage' => false,
            'isShell' => true,
            'accepting' => $accepting,
            'closedMessage' => $accepting ? null : $this->getClosedMessage($form),
            'spam' => $spam,
            'templatePath' => $templatePath,
        ], $options);

        if ($open === null) {
            return null;
        }

        $close = $this->renderTemplate($form, '_shellClose', [
            'accepting' => $accepting,
            'showCaptcha' => true,
            'showNav' => true,
            'spam' => $spam,
            'form' => $form,
            'settings' => $settings,
            'formId' => $formId,
            'templatePath' => $templatePath,
        ], $options);

        if ($close === null) {
            return null;
        }

        return new FormShell(
            new Markup($open, Craft::$app->charset),
            new Markup($close, Craft::$app->charset),
            $formId,
            $accepting,
        );
    }

    /**
     * Renders the closed-form message a form shows in place of its fields when
     * it isn't accepting submissions.
     *
     * @param array<string, mixed> $options
     */
    private function renderClosed(Form $form, array $options): ?Markup
    {
        $html = $this->renderTemplate($form, '_closed', [
            'form' => $form,
            'settings' => Plugin::getInstance()->getTranslations()->localizeSettings($form),
            'message' => $this->getClosedMessage($form),
        ], $options);

        return $html !== null ? new Markup($html, Craft::$app->charset) : null;
    }

    /**
     * The form's closed message, with any Twig in it resolved against the form.
     *
     * Shared by the renderer's closed partial and the submission paths (the
     * controller's flash/JSON, the GraphQL mutation's error), so a closed form
     * says the same thing however it was reached.
     */
    public function getClosedMessage(Form $form): string
    {
        $message = Plugin::getInstance()->getTranslations()->settingString($form, 'closedMessage');

        return $this->renderMessage($message, $form);
    }

    /**
     * Renders one of a form's settings strings - a success, error or closed
     * message - against the element it describes.
     *
     * These are author-written strings that may carry Twig (`{{ name }}`), and
     * a broken one must not take out the response the submitter is waiting on:
     * it falls back to its own raw source, which is at worst a message with a
     * tag showing.
     */
    public function renderMessage(string $template, Form|Submission $object): string
    {
        return $this->renderObjectString($template, $object, what: 'message', variables: $this->elementVariables($object));
    }

    /**
     * The element a settings string is rendered against, under the name an
     * author writes it by - `{{ submission.id }}`, as the settings screen's
     * own examples do. A bare object template only binds it as `object`, so
     * without this those examples rendered blank. The names match the ones a
     * notification already gets.
     *
     * @return array<string, Form|Submission|null>
     */
    private function elementVariables(Form|Submission $object): array
    {
        return $object instanceof Submission
            ? ['submission' => $object, 'form' => $object->getForm()]
            : ['form' => $object];
    }

    /**
     * The URL a form redirects to after a successful submission, with any Twig
     * in it resolved against the submission - or null when the form has none,
     * or the one it has could not be rendered.
     *
     * Falling back to no redirect (rather than to the raw source) is deliberate:
     * sending a submitter to a URL with an unrendered `{{ id }}` in it is worse
     * than leaving them on the form with its success message.
     */
    public function renderRedirectUrl(FormSettings $settings, Submission $submission): ?string
    {
        $url = $this->renderObjectString(
            $settings->redirectUrl,
            $submission,
            self::ON_ERROR_EMPTY,
            'redirect URL',
            $this->elementVariables($submission),
            self::CONTEXT_URL,
        );

        return $url !== '' ? $url : null;
    }

    /**
     * Renders an author-supplied string that may carry Twig - a settings
     * message, a redirect URL, an upload subfolder, a notification subject -
     * against the object it describes.
     *
     * Every one of those is written in the control panel by someone who is not
     * present for the request it finally runs in, so a broken one must never
     * take out the response the submitter is waiting on. What it yields
     * *instead* is the part that differs, and it is the caller's call, not this
     * method's: `ON_ERROR_SOURCE` returns the raw source, which is at worst a
     * message with a tag showing; `ON_ERROR_EMPTY` returns nothing, for the
     * places where a stray `{{ … }}` would land in an email address, a URL or a
     * folder path.
     *
     * This is the one place author strings become Twig. A new caller belongs
     * here rather than in another copy of the try/catch, and so does any future
     * policy about what such a string is allowed to do - which is also where
     * `$context` belongs, per internal/decisions/0087.
     *
     * The render itself runs sandboxed - see {@see renderSandboxed()} - because
     * whoever wrote `$template` holds `formable:saveForms`, not necessarily an
     * admin account, and the `craft` global is reachable from any Twig string
     * exactly the way it is from a real template. See internal/decisions/0099.
     *
     * Craft's `renderObjectTemplate()` runs with HTML escaping unconditionally
     * off, with no parameter to change that - it's how a form's own settings
     * strings have always been able to carry literal markup. So `$context`
     * doesn't switch escaping back on here; for {@see CONTEXT_HTML} it's the
     * caller's job to hand `$object`/`$variables` already made HTML-safe
     * (see {@see \bytesof\formable\services\Notifications::renderString()}, which
     * encodes the submitted values it builds those from before this method
     * ever sees them - encoding the *values*, not the whole rendered string,
     * is what keeps an author's own literal HTML in the template intact).
     * `$context` earns its keep here for {@see CONTEXT_ADDRESS} and
     * {@see CONTEXT_URL}, which this method does sanitize directly, since that
     * sanitization is safe to apply to the whole result regardless of what
     * produced it.
     *
     * @param string $onError self::ON_ERROR_SOURCE or self::ON_ERROR_EMPTY
     * @param string $what how the string is described in the warning log
     * @param array<string, mixed> $variables extra variables for the template, beyond `$object`
     * @param string $context one of the self::CONTEXT_* constants
     */
    public function renderObjectString(
        string $template,
        mixed $object,
        string $onError = self::ON_ERROR_SOURCE,
        string $what = 'settings string',
        array $variables = [],
        string $context = self::CONTEXT_TEXT,
    ): string {
        // No braces means no tags - skip the Twig round-trip, and skip treating
        // a literal address list or folder path as a template.
        if ($template === '' || !str_contains($template, '{')) {
            return $this->sanitizeForContext($template, $context);
        }

        try {
            // Site mode, always - and it is Craft's own default here. These are
            // front-end strings wherever they are rendered from, which for a
            // notification is a queue worker or the control panel.
            $rendered = $this->renderSandboxed($template, $object, $variables);

            return $this->sanitizeForContext($rendered, $context);
        } catch (Throwable $e) {
            Craft::warning(
                sprintf('Could not render a Formable %s: %s', $what, $e->getMessage()),
                __METHOD__,
            );

            return $this->sanitizeForContext($onError === self::ON_ERROR_SOURCE ? $template : '', $context);
        }
    }

    /**
     * Renders an object template with Twig's sandbox forced on, whatever the
     * site's own `general.enableTwigSandbox` says.
     *
     * `View::renderSandboxedObjectTemplate()` still gates itself on that
     * config setting, which defaults off and stays off on almost every
     * install - going through it here would leave the escalation this closes
     * exactly as open as before on every site that never touched the flag.
     * This reaches the `SandboxExtension` Craft already registered on the
     * site's Twig environment directly, so it doesn't depend on that setting,
     * and restores whatever sandboxed state it found rather than assuming it
     * was off - a render nested inside another sandboxed render (unlikely, but
     * this is the one path every author string takes) leaves the outer one
     * enabled.
     *
     * The policy behind that extension is left alone: it's Craft's own
     * default (`config/twig-sandbox.php`, sitewide-overridable), which already
     * keeps `craft`, `currentUser` and every other global off limits while
     * allowing the filters and functions an ordinary message needs. `Form` and
     * `Submission` open up the one thing that policy can't know in advance -
     * a form's own field handles - through
     * {@see \craft\web\twig\AllowableInSandbox} instead. See
     * internal/decisions/0099.
     *
     * @param array<string, mixed> $variables
     */
    private function renderSandboxed(string $template, mixed $object, array $variables): string
    {
        $extension = Craft::$app->getView()->getTwig(View::TEMPLATE_MODE_SITE)->getExtension(SandboxExtension::class);
        $alreadySandboxed = $extension->isSandboxed();

        if (!$alreadySandboxed) {
            $extension->enableSandbox();
        }

        try {
            return Craft::$app->getView()->renderObjectTemplate($template, $object, $variables);
        } finally {
            if (!$alreadySandboxed) {
                $extension->disableSandbox();
            }
        }
    }

    /**
     * The context-specific cleanup {@see renderObjectString()} applies to every
     * exit path - the rendered string, the error fallback, and the untouched
     * literal a brace-free template already returns.
     */
    private function sanitizeForContext(string $value, string $context): string
    {
        return match ($context) {
            self::CONTEXT_ADDRESS => str_replace(["\r", "\n"], '', $value),
            self::CONTEXT_URL => $this->sanitizeUrl(str_replace(["\r", "\n"], '', $value)),
            default => $value,
        };
    }

    /**
     * Discards a URL whose scheme could run script rather than navigate - a
     * `javascript:`/`data:`/`vbscript:` redirect target is never legitimate,
     * and the check runs after the Twig render because that's the only point a
     * submitted value could have put a scheme there that the author's own
     * template text didn't.
     */
    private function sanitizeUrl(string $url): string
    {
        return preg_match('~^\s*(?:javascript|data|vbscript):~i', $url) === 1 ? '' : $url;
    }

    /**
     * Renders one step of a form - a single page, or the review step - as the
     * markup that sits inside the `<form>`.
     *
     * This is what the AJAX navigation swaps in: the same partial the full
     * render uses for the current step, so a page looks identical whether it
     * arrived with the document or over a fetch.
     *
     * @param array<string, mixed> $options
     */
    public function renderStep(Form $form, Submission $submission, int $pageIndex, bool $review, array $options = []): ?Markup
    {
        $pages = $this->preparePages($form);
        $flow = Plugin::getInstance()->getPageFlow();

        $html = $this->renderTemplate($form, '_step', [
            'form' => $form,
            'pages' => $pages,
            'submission' => $submission,
            'settings' => Plugin::getInstance()->getTranslations()->localizeSettings($form),
            'errors' => $submission->getAllFieldErrors(),
            'values' => $submission->getValues(),
            'currentPage' => $pageIndex,
            'isReview' => $review,
            'sequence' => $flow->getPageSequence($submission),
            'progress' => $flow->getProgressPosition($submission, $pageIndex),
            'isLastPage' => $flow->isLastPage($submission, $pageIndex),
            // The full render computes this in the shell and passes it down;
            // here `_step` is the root template, so it has to arrive from PHP.
            // Without it the partial renders blank in production and throws in
            // devMode - and this path only ever runs for a multi-page form.
            'isMultiPage' => count($pages) > 1,
            // Only the captcha is needed for a re-rendered step - the honeypot
            // and timing fields live in the form shell, which never swaps, so
            // there's no timestamp to re-mint here.
            'spam' => ['captcha' => Plugin::getInstance()->getCaptchas()->getCaptchaForForm($form)?->getFrontEndData()],
            // `_step.twig` builds its own `formId` the same way `form.twig`
            // does, so the page/field ids this AJAX swap mints stay namespaced
            // the same way the initial render's did - see resolveFlow().
            'flowId' => $this->resolveFlow($form, $options)->id,
            'headingLevel' => $this->resolveHeadingLevel($options),
            'options' => $options,
        ], $options);

        return $html !== null ? new Markup($html, Craft::$app->charset) : null;
    }

    /**
     * Renders a single field, for authors assembling their own markup.
     *
     * @param array<string, mixed> $options
     */
    public function renderField(Form $form, string $handle, array $options = []): ?Markup
    {
        $pages = Plugin::getInstance()->getTranslations()->localizePages($form);
        $field = Plugin::getInstance()->getFields()->getFieldMap($pages)->byHandle($handle);

        if ($field === null) {
            return null;
        }

        $submission = $this->resolveSubmission($form, $options);

        $html = $this->renderTemplate($form, '_field', [
            'form' => $form,
            'field' => $field,
            'value' => $submission->getValue($handle),
            'errors' => $submission->getFieldErrors($handle),
        ], $options);

        return $html !== null ? new Markup($html, Craft::$app->charset) : null;
    }

    /**
     * The form's layout with every field config replaced by its instance.
     *
     * Templates get objects rather than raw JSON, so an override template
     * never has to know the storage shape - and never has to reach back into
     * the field registry to make sense of it.
     *
     * The instances come from the request's shared {@see FieldMap}, so the same
     * objects the conditions engine and validation saw are the ones rendered.
     * Pages are re-keyed from 0 because the templates index this list by step
     * number.
     *
     * @return array<int, array{id: string, label: string, settings: array<string, mixed>, rows: array<int, array{id: string, fields: array<int, FormField>}>}>
     */
    public function preparePages(Form $form): array
    {
        $pages = Plugin::getInstance()->getTranslations()->localizePages($form);

        return array_values(Plugin::getInstance()->getFields()->getFieldMap($pages)->getPages());
    }

    /**
     * @param array<int, array{rows: array<int, array{fields: array<int, FormField>}>}> $pages
     */
    private function hasFileUploads(array $pages): bool
    {
        foreach ($pages as $page) {
            foreach ($page['rows'] as $row) {
                foreach ($row['fields'] as $field) {
                    if ($field instanceof FileUpload) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * Registers the front-end bundle, and the optional theme stylesheet.
     *
     * @param array<string, mixed> $options
     */
    public function registerAssets(Form $form, array $options = []): void
    {
        $view = Craft::$app->getView();
        $templateMode = $view->getTemplateMode();

        // Asset bundles publish the same either way, but registering while the
        // view is in CP mode would leave the tags in the CP's buffer.
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        try {
            $view->registerAssetBundle(FrontendAsset::class);

            match ($this->resolveThemeLevel($form, $options['theme'] ?? null)) {
                // `none` is a stored legacy value, not a lower tier than `contract` -
                // see decision 0062. Without this the a11y contract's
                // `.formable-visually-hidden` and `[hidden]` rules have no stylesheet
                // to come from, so hidden labels and conditionally-hidden fields
                // render visible.
                Settings::THEME_LEVEL_NONE, Settings::THEME_LEVEL_CONTRACT => $view->registerAssetBundle(ContractAsset::class),
                Settings::THEME_LEVEL_RESET => $view->registerAssetBundle(ResetAsset::class),
                Settings::THEME_LEVEL_DEFAULT => $view->registerAssetBundle(ThemeAsset::class),
            };

            $paletteDeclarations = $this->resolvePalette($form, $options)->toCssDeclarations();

            if ($paletteDeclarations !== []) {
                $hash = $this->paletteHash($paletteDeclarations);

                // Keyed by the hash, not a fixed name, so two forms sharing a
                // palette register the same block once and a form rendered
                // twice on one page doesn't duplicate it - see
                // internal/decisions/0091.
                $view->registerCss($this->buildPaletteCss($hash, $paletteDeclarations), [], "formable-palette-$hash");
            }
        } catch (Throwable $e) {
            Craft::warning(
                sprintf('Could not register the Formable front-end assets: %s', $e->getMessage()),
                __METHOD__,
            );
        } finally {
            $view->setTemplateMode($templateMode);
        }
    }

    /**
     * Resolves the level a form actually renders at, in order of precedence:
     * the per-render `theme` option, then the form's own
     * {@see FormSettings::$themeLevel} override, then
     * {@see Settings::$themeLevel} globally.
     *
     * The option accepts a level string (`'none'` / `'contract'` / `'reset'` /
     * `'default'`) and the legacy boolean this replaced - `true` for
     * `default`, `false` for `none` - so templates written against the
     * pre-1.1 `theme: true` option keep working. A form left on
     * {@see FormSettings::THEME_LEVEL_INHERIT} (the default) falls straight
     * through to the global setting.
     */
    private function resolveThemeLevel(Form $form, mixed $option): string
    {
        if (is_bool($option)) {
            return $option ? Settings::THEME_LEVEL_DEFAULT : Settings::THEME_LEVEL_NONE;
        }

        $levels = [
            Settings::THEME_LEVEL_NONE,
            Settings::THEME_LEVEL_CONTRACT,
            Settings::THEME_LEVEL_RESET,
            Settings::THEME_LEVEL_DEFAULT,
        ];

        if (is_string($option) && in_array($option, $levels, true)) {
            return $option;
        }

        $formLevel = $form->getFormSettings()->themeLevel;

        if (in_array($formLevel, $levels, true)) {
            return $formLevel;
        }

        return Plugin::getInstance()->getSettings()->themeLevel;
    }

    /**
     * Resolves the palette a form actually renders with, in the same
     * precedence order as {@see resolveThemeLevel()}: the per-render
     * `colorScheme` option, then the form's own
     * {@see FormSettings::$colorScheme} override, then
     * {@see Settings::$colorScheme} globally. Emitted as
     * `data-formable-scheme` on the `<form>` - see internal/decisions/0071.
     */
    private function resolveColorScheme(Form $form, mixed $option): string
    {
        $schemes = [
            Settings::COLOR_SCHEME_LIGHT,
            Settings::COLOR_SCHEME_DARK,
            Settings::COLOR_SCHEME_AUTO,
        ];

        if (is_string($option) && in_array($option, $schemes, true)) {
            return $option;
        }

        $formScheme = $form->getFormSettings()->colorScheme;

        if (in_array($formScheme, $schemes, true)) {
            return $formScheme;
        }

        return Plugin::getInstance()->getSettings()->colorScheme;
    }

    /**
     * Resolves the brand palette a form actually renders with: the form's
     * own override ({@see FormSettings::toPalette()}) layered over
     * {@see Settings::$palette} globally, via {@see Palette::mergeOver()}.
     *
     * Unlike {@see resolveThemeLevel()} and {@see resolveColorScheme()} there
     * is no per-render option - `internal/decisions/0091`'s docs
     * section deliberately proposes none. `$options` is only read for the
     * resolved theme level: a palette is meaningless at `contract`/`none`,
     * which ship no paint to receive it (§3), so both the CSS this feeds
     * {@see registerAssets()} and the `data-formable-palette*` attributes
     * {@see renderForm()} emits stay empty there rather than pointing at a
     * block nothing will ever match.
     *
     * The per-form override is Pro (`FormSettings::getSettingsSchema()`
     * gates all six `palette*` fields with `'proOnly' => true`); a Lite
     * install ignores it here too, not just in the builder, since the fields
     * still round-trip through a form saved before a downgrade.
     *
     * @param array<string, mixed> $options
     */
    private function resolvePalette(Form $form, array $options): Palette
    {
        $themeLevel = $this->resolveThemeLevel($form, $options['theme'] ?? null);

        if (in_array($themeLevel, [Settings::THEME_LEVEL_NONE, Settings::THEME_LEVEL_CONTRACT], true)) {
            return new Palette();
        }

        $globalPalette = Plugin::getInstance()->getSettings()->palette;

        if (!Plugin::getInstance()->isPro()) {
            return $globalPalette;
        }

        return $form->getFormSettings()->toPalette()->mergeOver($globalPalette);
    }

    /**
     * A short, stable key for a resolved palette's declarations - shared by
     * the `data-formable-palette` attribute {@see renderForm()} emits and the
     * `registerCss()` key {@see registerAssets()} registers the matching
     * block under, so a hash a template carries always names a block that was
     * actually registered.
     *
     * @param array<string, string> $declarations
     */
    private function paletteHash(array $declarations): string
    {
        return substr(md5(json_encode($declarations, JSON_THROW_ON_ERROR)), 0, 8);
    }

    /**
     * The `<style>` block body for one resolved palette - see
     * `internal/decisions/0091`, "Why the block targets the form".
     *
     * Targets the form element itself, not `:root`: {@see resolveColorScheme}'s
     * scheme blocks in `formable-tokens.css` re-declare several of these same
     * tokens on `.formable-form`, and a declaration on the element beats an
     * inherited one - a `:root`-scoped block would be silently shadowed
     * there. The second, bare-attribute selector covers the success notice
     * and the resume page, which render outside `<form>` but carry the same
     * `data-formable-palette` attribute.
     *
     * @param array<string, string> $declarations
     */
    private function buildPaletteCss(string $hash, array $declarations): string
    {
        $body = '';

        foreach ($declarations as $property => $value) {
            $body .= "  $property: $value;\n";
        }

        return sprintf(
            ".formable-form[data-formable-palette='%1\$s'],\n[data-formable-palette='%1\$s'] {\n%2\$s}\n",
            $hash,
            $body,
        );
    }

    /**
     * The heading level a rendered form's step/page/review/success headings
     * start from - a page label, the review heading and the success heading
     * all render at this level, with the review's per-page sub-headings one
     * level below (clamped to `<h6>`, past which HTML has nothing lower).
     *
     * There is no form- or site-level setting for this, unlike
     * {@see resolveThemeLevel()} and {@see resolveColorScheme()}: a form's
     * place in a host page's own outline is a property of where it's
     * embedded, not of the form itself, so it only ever makes sense as a
     * per-render option (L6).
     *
     * @param array<string, mixed> $options
     */
    private function resolveHeadingLevel(array $options): int
    {
        $level = $options['headingLevel'] ?? 2;

        return is_numeric($level) ? max(1, min(6, (int)$level)) : 2;
    }

    /**
     * The override path a form renders through, if it has one.
     *
     * Handed to templates so each `include` can resolve itself - see
     * {@see resolveTemplatePath()}.
     *
     * @param array<string, mixed> $options
     */
    public function getTemplatePath(Form $form, array $options = []): ?string
    {
        return $this->getOverridePath($form, $options);
    }

    /**
     * Resolves one template name against an override path, falling back to the
     * plugin's pack - or, for a plugin template outside the front-end pack such
     * as the notification email, to the root the caller names.
     *
     * Resolution is per-template, not per-root: an author who overrides only
     * `fields/text` gets their version of that one file and the plugin's for
     * everything else. Pointing every include at the override directory
     * instead would make the first override oblige them to copy all
     * twenty-odd remaining templates.
     */
    public function resolveTemplatePath(string $name, ?string $overridePath = null, string $root = self::TEMPLATE_ROOT): string
    {
        $overridePath = $this->normalizePath($overridePath);
        $fallback = "$root/$name";

        if ($overridePath === null) {
            return $fallback;
        }

        $override = "$overridePath/$name";

        return ($this->overrideExists[$override] ??= $this->siteTemplateExists($override)) ? $override : $fallback;
    }

    private function siteTemplateExists(string $template): bool
    {
        $view = Craft::$app->getView();
        $templateMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        try {
            return $view->doesTemplateExist($template);
        } catch (Throwable) {
            // An unreadable override directory is not worth failing the whole
            // render over - fall through to the plugin's own template.
            return false;
        } finally {
            $view->setTemplateMode($templateMode);
        }
    }

    /**
     * Renders one of the pack's templates in site mode.
     *
     * @param array<string, mixed> $variables
     * @param array<string, mixed> $options
     */
    private function renderTemplate(Form $form, string $name, array $variables, array $options): ?string
    {
        $view = Craft::$app->getView();
        $templateMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        try {
            $templatePath = $this->getTemplatePath($form, $options);

            // Passed down so every nested include can resolve itself against
            // the same override, one template at a time.
            $variables['templatePath'] = $templatePath;

            return $view->renderTemplate($this->resolveTemplatePath($name, $templatePath), $variables);
        } catch (Throwable $e) {
            Craft::error(
                sprintf('Could not render the Formable template “%s”: %s', $name, $e->getMessage()),
                __METHOD__,
            );

            // Swallowing here would leave the author staring at a blank page
            // with nothing in the logs to act on, so re-throw in dev.
            if (Craft::$app->getConfig()->getGeneral()->devMode) {
                throw $e;
            }

            return null;
        } finally {
            $view->setTemplateMode($templateMode);
        }
    }

    /**
     * @param array<string, mixed> $options
     */
    private function getOverridePath(Form $form, array $options): ?string
    {
        return $this->normalizePath($options['templatePath'] ?? $form->getFormSettings()->templateOverridePath);
    }

    private function normalizePath(mixed $path): ?string
    {
        if (!is_string($path)) {
            return null;
        }

        $path = trim($path, '/ ');

        return $path !== '' ? $path : null;
    }

    private function resolveForm(Form|string|null $form): ?Form
    {
        if ($form instanceof Form) {
            return $form;
        }

        if (!is_string($form) || $form === '') {
            return null;
        }

        return Plugin::getInstance()->getForms()->getFormByHandle($form);
    }

    /**
     * The submission the form renders against.
     *
     * After a failed non-AJAX submit the controller stashes the rejected
     * submission in the request's route params, so the re-rendered page shows
     * the submitter's own values and errors instead of an empty form.
     *
     * Failing that, a multi-page form in flight renders against the answers
     * held for the session: without this a non-JS submitter stepping back to
     * page one would be shown empty inputs and would overwrite what they had
     * already answered by stepping forward again.
     *
     * @param array<string, mixed> $options
     */
    private function resolveSubmission(Form $form, array $options): Submission
    {
        $submission = $options['submission'] ?? null;

        if ($submission instanceof Submission && $submission->formId === $form->id) {
            return $submission;
        }

        if (!Craft::$app->getRequest()->getIsConsoleRequest()) {
            $stashed = Craft::$app->getUrlManager()->getRouteParams()['formableSubmission'] ?? null;

            if ($stashed instanceof Submission && $stashed->formId === $form->id) {
                return $stashed;
            }

            $flowId = $options['flowId'] ?? $this->flowIdFromRequest($form);
            $inFlight = Plugin::getInstance()->getProgress()->load(
                $form,
                is_string($flowId) ? $flowId : null,
            );

            if ($inFlight !== null) {
                return $inFlight;
            }
        }

        return Plugin::getInstance()->getSubmissions()->createSubmission($form);
    }

    /**
     * The id for this pass through the form - one value shared by the shell's
     * hidden `formableFlow` input and every progress lookup for this render.
     *
     * A caller can pin it (the resume landing page does, so its `attach()` and
     * its render agree); otherwise it is whatever the request carries - a
     * re-rendered failed post has it in the body, a redirect after a non-JS
     * next/back has it on the query string - and failing all of that a fresh
     * one, planted now so the first post carries it back.
     *
     * @param array<string, mixed> $options
     */
    private function resolveFlow(Form $form, array $options): ResolvedFlow
    {
        $pinned = $options['flowId'] ?? null;

        if (is_string($pinned) && $pinned !== '') {
            return new ResolvedFlow($pinned, false);
        }

        $fromRequest = $this->flowIdFromRequest($form);

        if ($fromRequest !== null) {
            return new ResolvedFlow($fromRequest, false);
        }

        return new ResolvedFlow(Plugin::getInstance()->getProgress()->newFlowId(), true);
    }

    /**
     * Where the front-end bundle fetches fresh request-scoped tokens from, or
     * null when the site has switched the refresh off. The template emits the
     * attribute only when this is set, so the switch lives on the server and
     * the bundle never has to know about it.
     */
    private function tokensUrl(): ?string
    {
        if (!Plugin::getInstance()->getSettings()->refreshCachedTokens) {
            return null;
        }

        return UrlHelper::actionUrl('formable/submissions/tokens');
    }

    /**
     * The flow id the current request carries for this form, or null.
     *
     * The body copy is trusted only when the post was aimed at this form, so a
     * failed submit of one form on a page doesn't hand its id to another. The
     * query copy (from a non-JS navigation redirect) isn't handle-scoped - the
     * same single-multi-page-form-per-page assumption `formablePage` already
     * makes.
     */
    private function flowIdFromRequest(Form $form): ?string
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return null;
        }

        if ($request->getBodyParam('handle') === $form->handle) {
            $fromBody = Progress::normalizeFlowId($request->getBodyParam('formableFlow'));

            if ($fromBody !== null) {
                return $fromBody;
            }
        }

        return Progress::normalizeFlowId($request->getQueryParam('formableFlow'));
    }

    /**
     * The page a fresh render should open on.
     *
     * After a failed or navigating non-JS post the controller stashes the page
     * the submitter was on in the route params; a resumed submission carries
     * its own; otherwise a form opens on its first page. Whatever comes out is
     * clamped into range, so a stale `?formablePage=9` can't render nothing.
     *
     * @param array<string, mixed> $options
     */
    private function resolveCurrentPage(Form $form, Submission $submission, array $options): int
    {
        $page = $options['page']
            ?? $this->routeParam('formablePage')
            ?? $submission->pageIndex;

        $page = is_numeric($page) ? (int)$page : 0;
        $lastPage = max(0, count($form->getPages()) - 1);

        return max(0, min($page, $lastPage));
    }

    /**
     * Whether this render is the review step rather than a page.
     *
     * Only reachable on the genuinely last page of a form that has a review
     * step turned on - a `?formableReview=1` on any earlier page is ignored, so
     * a hand-edited URL can't skip the pages in between.
     *
     * @param array<string, mixed> $options
     */
    private function resolveReview(Form $form, Submission $submission, int $currentPage, array $options): bool
    {
        if (!$form->getFormSettings()->showReviewPage) {
            return false;
        }

        $flag = $options['review'] ?? $this->routeParam('formableReview');

        return (bool)$flag
            && Plugin::getInstance()->getPageFlow()->isLastPage($submission, $currentPage);
    }

    /**
     * A navigation hint, from wherever this request carries it.
     *
     * A failed non-JS post stashes it in the route params (same request); a
     * successful next/back redirects with it as a query param (next request).
     * Route params win, since they belong to the post being re-rendered.
     */
    private function routeParam(string $name): mixed
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return null;
        }

        return Craft::$app->getUrlManager()->getRouteParams()[$name]
            ?? $request->getQueryParam($name);
    }

    /**
     * What the last non-AJAX post of this form flashed under `$prefix`, if the
     * current request is the redirect that followed it.
     */
    private function formFlash(Form $form, string $prefix): ?string
    {
        // No session on a console request (a form rendered by a queue job for
        // an email preview, say), and no flash to read either.
        if ($form->handle === null || Craft::$app->getRequest()->getIsConsoleRequest()) {
            return null;
        }

        $message = Craft::$app->getSession()->getFlash($prefix . $form->handle);

        return is_string($message) && $message !== '' ? $message : null;
    }

    /**
     * Where the success state's "Submit another response" link goes, or null
     * for no link at all.
     *
     * The default is the URL the form was rendered on: requesting it again
     * re-renders the same embed with a blank form, and arriving by a full
     * navigation brings a fresh CSRF token and honeypot timestamp with it.
     *
     * A template that renders a form on a single-use URL has to say so,
     * because the plugin has no way to tell: `_resume` passes
     * `restartUrl: false`, since by the time the success state renders there
     * the resume token in the URL has been spent and the link would 404.
     *
     * @param array<string, mixed> $options
     */
    private function resolveRestartUrl(array $options): ?string
    {
        if (array_key_exists('restartUrl', $options)) {
            $url = $options['restartUrl'];

            return is_string($url) && $url !== '' ? $url : null;
        }

        $request = Craft::$app->getRequest();

        return $request->getIsConsoleRequest() ? null : $request->getUrl();
    }
}
