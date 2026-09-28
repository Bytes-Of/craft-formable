<?php

declare(strict_types=1);

namespace bytesof\formable\models;

use bytesof\formable\Plugin;
use Craft;
use craft\base\Model;
use DateTime;
use DateTimeInterface;

/**
 * Per-form settings, stored as the `settings` JSON blob on the form.
 *
 * Kept as a model rather than a loose array so the builder's Settings tab can
 * be generated from the same schema the server validates against.
 *
 * @internal
 */
final class FormSettings extends Model
{
    public const SUBMIT_METHOD_PAGE = 'page';
    public const SUBMIT_METHOD_AJAX = 'ajax';

    public const SUCCESS_MESSAGE = 'message';
    public const SUCCESS_REDIRECT = 'redirect';

    public const DENSITY_COMFORTABLE = 'comfortable';
    public const DENSITY_COMPACT = 'compact';

    public const LABEL_POSITION_TOP = 'top';
    public const LABEL_POSITION_LEFT = 'left';

    public const APPEARANCE_PLAIN = 'plain';
    public const APPEARANCE_BORDERED = 'bordered';

    /**
     * Inherits {@see Settings::$colorScheme} - same sentinel shape as
     * {@see THEME_LEVEL_INHERIT}, and for the same reason: the global default
     * is `light`, not a value free to double as "unset".
     */
    public const COLOR_SCHEME_INHERIT = '';

    /**
     * Inherits {@see Settings::$themeLevel} - the sentinel rather than one of
     * the real levels, since the global default is `default`, not `none`, so
     * there's no level value free to mean "unset".
     */
    public const THEME_LEVEL_INHERIT = '';

    /**
     * Inherits the global {@see Palette::$radius} / {@see Palette::$buttonStyle}
     * - the same empty-string sentinel the four palette colours use, and the
     * same one {@see COLOR_SCHEME_INHERIT} and {@see THEME_LEVEL_INHERIT} use
     * for the presentation settings beside these.
     */
    public const PALETTE_RADIUS_INHERIT = '';
    public const PALETTE_BUTTON_STYLE_INHERIT = '';

    public string $submitButtonLabel = 'Submit';

    /**
     * Whether the form posts normally or over AJAX. Both paths are handled by
     * the submission controller in M4; this only picks the default markup.
     */
    public string $submitMethod = self::SUBMIT_METHOD_PAGE;

    public string $successBehavior = self::SUCCESS_MESSAGE;

    public string $successMessage = 'Thanks - your submission has been received.';

    /**
     * Optional second line under {@see $successMessage} - what happens next, how
     * long a reply takes, and so on. Rendered the same way, so it can also carry
     * a reference number (`{{ submission.id }}`); kept separate from the message
     * itself because the message is styled as the success screen's heading and a
     * multi-sentence explanation doesn't belong in one.
     */
    public string $successDetails = '';

    /**
     * Where to send the submitter when `successBehavior` is `redirect`. May
     * contain Twig, resolved against the submission at render time.
     */
    public string $redirectUrl = '';

    public string $errorMessage = 'There was a problem with your submission. Please check the fields below.';

    /**
     * How tightly the rendered form is spaced.
     *
     * `comfortable` (the default) is the 1.0 spacing, unchanged. `compact`
     * emits `formable-form--compact` on the wrapper, which the optional
     * stylesheet reads to redefine only the control-height, control-padding and
     * gap tokens from Wave 2.6 - no component rule has a compact variant, so a
     * form left on `comfortable` renders byte-for-byte as before. A form running
     * without the stylesheet (`themeLevel: 'none'`) does its own spacing and
     * ignores this.
     */
    public string $density = self::DENSITY_COMFORTABLE;

    /**
     * An author-supplied class added to the rendered `<form>`, alongside
     * whatever `density`/`labelPosition`/`appearance` add of their own. The
     * per-field equivalent, {@see \bytesof\formable\base\FormField::$cssClasses},
     * is plural because a field's `class` attribute commonly needs more than
     * one; a form only ever adds this one wrapper class, so it stays singular.
     */
    public string $cssClass = '';

    /**
     * Per-form override of {@see Settings::$themeLevel}. {@see THEME_LEVEL_INHERIT}
     * (the default) defers to the global setting; set to one of
     * {@see Settings::THEME_LEVEL_NONE}, {@see Settings::THEME_LEVEL_CONTRACT},
     * {@see Settings::THEME_LEVEL_RESET} or {@see Settings::THEME_LEVEL_DEFAULT}
     * to pin this form to a level regardless of what the rest of the site
     * uses - a marketing form that wants the full theme on a site otherwise
     * running `reset`, say.
     */
    public string $themeLevel = self::THEME_LEVEL_INHERIT;

    /**
     * Per-form override of {@see Settings::$colorScheme}. {@see COLOR_SCHEME_INHERIT}
     * (the default) defers to the global setting; set to one of
     * {@see Settings::COLOR_SCHEME_LIGHT}, {@see Settings::COLOR_SCHEME_DARK}
     * or {@see Settings::COLOR_SCHEME_AUTO} to pin this form's palette
     * regardless of what the rest of the site uses.
     */
    public string $colorScheme = self::COLOR_SCHEME_INHERIT;

    /**
     * `top` (the default) keeps a label above its field. `left` emits
     * `formable-form--labels-left`, which moves a plain field's label beside
     * its input; a fieldset field (Name, Address, Radio, Checkboxes, Table) is
     * unaffected - see decision 0048.
     */
    public string $labelPosition = self::LABEL_POSITION_TOP;

    /**
     * `plain` (the default) renders the form as before. `bordered` emits
     * `formable-form--bordered`, the boxed/card treatment from decision 0048.
     */
    public string $appearance = self::APPEARANCE_PLAIN;

    /**
     * Per-form override of {@see Settings::$palette} (Pro). Flat fields
     * rather than a nested {@see Palette}, matching {@see $colorScheme} /
     * {@see $labelPosition} / {@see $appearance} above - the builder's
     * settings schema and its Vue panels look values up by a single flat
     * key ({@see getSettingsSchema()}), not a dotted path.
     *
     * An unset colour (`''`, the default) falls through to the global
     * palette - see {@see Palette::mergeOver()}, which
     * {@see \bytesof\formable\services\Rendering::resolvePalette()} calls with
     * the {@see Palette} {@see toPalette()} assembles from these.
     */
    public string $paletteBrand = '';

    public string $paletteSurface = '';

    public string $paletteText = '';

    public string $paletteBorder = '';

    /**
     * Per-form override of {@see Palette::$radius} / {@see Palette::$buttonStyle},
     * carrying the same unset sentinel the four colours above do so the
     * global value stays reachable from a Pro form - see
     * {@see PALETTE_RADIUS_INHERIT} and internal/decisions/0090.
     */
    public string $paletteRadius = self::PALETTE_RADIUS_INHERIT;

    public string $paletteButtonStyle = self::PALETTE_BUTTON_STYLE_INHERIT;

    /**
     * Confine the form to a date window (Pro).
     *
     * Off by default; when on, {@see $scheduleOpenDate} and
     * {@see $scheduleCloseDate} bound when the form accepts submissions. Either
     * bound is independently optional - an open date with no close date opens
     * the form from a moment on, a close date with no open date closes it at
     * one. The enforcement lives in {@see isWithinScheduleWindow()}, folded into
     * {@see \bytesof\formable\elements\Form::isAcceptingSubmissions()}; Lite never
     * enforces it.
     */
    public bool $scheduleEnabled = false;

    public ?DateTime $scheduleOpenDate = null;

    public ?DateTime $scheduleCloseDate = null;

    /**
     * Cap the number of submissions a form accepts (Pro).
     *
     * Off by default; when on, the form stops accepting once it holds
     * {@see $maxSubmissions} completed, non-spam submissions - enforced in the
     * same {@see \bytesof\formable\elements\Form::isAcceptingSubmissions()} gate as
     * the schedule window, and showing the same closed message. Lite never
     * enforces it.
     */
    public bool $limitSubmissions = false;

    /**
     * The cap {@see $limitSubmissions} applies. Only consulted when the limit is
     * on.
     */
    public int $maxSubmissions = 100;

    /**
     * Shown in place of the form when it isn't accepting submissions -
     * disabled, or outside its schedule window. Twig-renderable against the
     * form, so a message can name it or interpolate a date.
     */
    public string $closedMessage = 'This form is currently closed and not accepting submissions.';

    public const PROGRESS_NONE = 'none';
    public const PROGRESS_BAR = 'bar';
    public const PROGRESS_STEPS = 'steps';

    /**
     * What happens to a submission a spam check catches.
     *
     * `flag` keeps it - stored with its `isSpam` bit set and held back from
     * notifications and integrations, so it lands in the CP's spam queue for a
     * human to look at. `reject` discards it outright. Either way the submitter
     * is shown the normal success response, so a bot learns nothing.
     */
    public const SPAM_ACTION_FLAG = 'flag';
    public const SPAM_ACTION_REJECT = 'reject';

    /**
     * No captcha. A per-form captcha choice defaults here; the provider handles
     * ({@see \bytesof\formable\services\Captchas}) fill out the rest of the range.
     */
    public const CAPTCHA_NONE = '';

    /**
     * Multi-page forms only: show a read-only review of all answers before the
     * final submit.
     */
    public bool $showReviewPage = false;

    public string $reviewPageLabel = 'Review your answers';

    /**
     * How a multi-page form reports where the submitter is. Ignored by
     * single-page forms, which have nothing to report.
     */
    public string $progressIndicator = self::PROGRESS_STEPS;

    public string $nextButtonLabel = 'Next';

    public string $backButtonLabel = 'Back';

    /**
     * Whether a submitter can park a part-filled form and come back to it.
     *
     * Storing an incomplete submission means keeping personal data the
     * submitter never actually sent, so this is opt-in per form and the rows
     * it creates expire on their own.
     */
    public bool $enableSaveAndResume = false;

    public string $saveAndResumeLabel = 'Save and continue later';

    /**
     * How long a saved-but-unfinished submission stays resumable.
     */
    public int $saveAndResumeExpiryDays = 30;

    public bool $storeSubmissions = true;

    /**
     * Whether a logged-in submitter can edit or delete their own completed
     * submissions from the front end, through
     * {@see \bytesof\formable\controllers\SubmissionsController::actionUpdateOwn()}
     * and {@see \bytesof\formable\controllers\SubmissionsController::actionDeleteOwn()}.
     *
     * Off by default: those two actions are reachable by direct POST on any
     * install, whether or not a self-service page was ever built for this
     * form, so a form only grants access once an author has actually opted
     * in. A Pro feature - Lite refuses both actions outright regardless of
     * this flag, since the gate in
     * {@see \bytesof\formable\controllers\SubmissionsController::resolveOwnSubmission()}
     * checks the edition first.
     */
    public bool $enableSelfService = false;

    /**
     * GDPR opt-ins - off by default so a fresh form collects nothing the
     * author didn't ask for.
     */
    public bool $collectIp = false;

    public bool $collectUserAgent = false;

    /**
     * Auto-purge completed submissions after a retention window - the GDPR
     * "don't keep it longer than you need it" control. Off by default; a form
     * keeps its submissions until someone deletes them.
     */
    public bool $dataRetentionEnabled = false;

    /**
     * Days a completed submission is kept before the retention purge removes
     * it. Only consulted when {@see $dataRetentionEnabled} is on.
     */
    public int $dataRetentionDays = 90;

    /**
     * A directory in the site's own templates folder to render this form
     * through. Templates found there win; anything missing falls back to the
     * plugin's pack, so an author can override one field template without
     * copying the rest.
     */
    public string $templateOverridePath = '';

    /**
     * The honeypot: a decoy field hidden from people but not from the naive
     * bots that fill every input they find. On by default - it costs a real
     * submitter nothing and stops the cheapest kind of spam.
     */
    public bool $enableHoneypot = true;

    /**
     * The JavaScript check: a token the front-end bundle sets that a bot
     * posting the form directly never will. Off by default - turning it on
     * flags any submission made with JavaScript disabled, which is a real (if
     * small) slice of genuine visitors.
     */
    public bool $enableJsCheck = false;

    /**
     * Reject a form submitted faster than a person plausibly could. Zero turns
     * the check off; a handful of seconds catches scripts that post instantly
     * without deterring someone who actually read the form.
     */
    public int $minSubmitTime = 0;

    /**
     * What to do with a submission a spam check catches - one of the
     * `SPAM_ACTION_*` constants. Defaults to flagging rather than rejecting, so
     * a false positive is recoverable from the spam queue instead of lost.
     */
    public string $spamAction = self::SPAM_ACTION_FLAG;

    /**
     * The captcha to require, by provider handle, or {@see CAPTCHA_NONE}. A Pro
     * feature; ignored in Lite, and silently skipped if the chosen provider has
     * no keys configured, so a form never breaks over a half-set-up captcha.
     */
    public string $captcha = self::CAPTCHA_NONE;

    /**
     * Per-form integration config, keyed by the global integration's handle:
     * `{ enabled, values: { target => fieldHandle }, conditions }`. A Pro
     * feature, opaque here - the model only round-trips it; the integrations
     * service resolves the mapping and ignores entries for integrations that no
     * longer exist, the same way an unknown captcha handle is treated as none.
     * Keyed by handle rather than ID so an exported form re-imports cleanly.
     *
     * @var array<string, mixed>
     */
    public array $integrations = [];

    /**
     * Whether a moment falls inside the form's schedule window.
     *
     * Always true when scheduling is off. Each bound is optional and treated as
     * half-open: the form is open from {@see $scheduleOpenDate} inclusive up to
     * {@see $scheduleCloseDate} exclusive, so a close date is the first instant
     * the form is shut rather than the last it's open.
     *
     * The edition gate lives one level up in {@see \bytesof\formable\elements\Form::isAcceptingSubmissions()}
     * - this only answers the date question, which keeps it pure and testable
     * without a booted plugin.
     */
    public function isWithinScheduleWindow(?DateTimeInterface $now = null): bool
    {
        if (!$this->scheduleEnabled) {
            return true;
        }

        $now ??= new DateTime('now');

        if ($this->scheduleOpenDate !== null && $now < $this->scheduleOpenDate) {
            return false;
        }

        if ($this->scheduleCloseDate !== null && $now >= $this->scheduleCloseDate) {
            return false;
        }

        return true;
    }

    /**
     * Whether a submission count has met or exceeded the form's cap.
     *
     * Always false when the limit is off, so a form with the toggle switched
     * off is never treated as full whatever count it's handed. When on, the cap
     * is inclusive - reaching {@see $maxSubmissions} shuts the form, so a cap of
     * N accepts exactly N submissions.
     *
     * The caller supplies the live count rather than this querying it: the
     * decision stays pure and testable, while the actual count - which needs the
     * database - lives with the edition gate in
     * {@see \bytesof\formable\elements\Form::isAcceptingSubmissions()}. Lite never
     * consults it.
     */
    public function hasReachedSubmissionLimit(int $count): bool
    {
        if (!$this->limitSubmissions) {
            return false;
        }

        return $count >= $this->maxSubmissions;
    }

    /**
     * Assembles this form's flat `palette*` fields into a {@see Palette},
     * the shape {@see Palette::mergeOver()} and
     * {@see \bytesof\formable\services\Rendering::resolvePalette()} work with.
     * Pure - callers are responsible for the Pro gate, the same way
     * {@see \bytesof\formable\elements\Form::isAcceptingSubmissions()} gates
     * {@see isWithinScheduleWindow()} rather than this deciding it.
     *
     * The result is a merge input, not a resolved palette: every field may
     * carry the unset sentinel, including radius and button style, which
     * {@see Palette}'s own rules require a concrete value for. Validate these
     * fields here, on the form settings, not on what this returns.
     */
    public function toPalette(): Palette
    {
        $palette = new Palette();
        $palette->brand = $this->paletteBrand;
        $palette->surface = $this->paletteSurface;
        $palette->text = $this->paletteText;
        $palette->border = $this->paletteBorder;
        $palette->radius = $this->paletteRadius;
        $palette->buttonStyle = $this->paletteButtonStyle;

        return $palette;
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['submitButtonLabel', 'nextButtonLabel', 'backButtonLabel'], 'required'];
        $rules[] = [
            [
                'submitButtonLabel', 'successMessage', 'successDetails', 'redirectUrl', 'errorMessage',
                'templateOverridePath', 'reviewPageLabel', 'nextButtonLabel',
                'backButtonLabel', 'saveAndResumeLabel', 'closedMessage', 'cssClass',
            ],
            'string',
        ];
        // Kept inside the templates directory: a path that climbs out of it
        // would let a form render arbitrary files off disk.
        $rules[] = [
            ['templateOverridePath'],
            'match',
            'pattern' => '/^[A-Za-z0-9_\-]+(\/[A-Za-z0-9_\-]+)*$/',
            'message' => Craft::t('formable', 'Enter a path relative to your templates folder, e.g. {example}.', [
                'example' => '_forms/contact',
            ]),
            'skipOnEmpty' => true,
        ];
        $rules[] = [['submitMethod'], 'in', 'range' => [self::SUBMIT_METHOD_PAGE, self::SUBMIT_METHOD_AJAX]];
        $rules[] = [['successBehavior'], 'in', 'range' => [self::SUCCESS_MESSAGE, self::SUCCESS_REDIRECT]];
        $rules[] = [['density'], 'in', 'range' => [self::DENSITY_COMFORTABLE, self::DENSITY_COMPACT]];
        $rules[] = [
            ['themeLevel'],
            'in',
            'range' => [
                self::THEME_LEVEL_INHERIT,
                Settings::THEME_LEVEL_NONE,
                Settings::THEME_LEVEL_CONTRACT,
                Settings::THEME_LEVEL_RESET,
                Settings::THEME_LEVEL_DEFAULT,
            ],
        ];
        $rules[] = [
            ['colorScheme'],
            'in',
            'range' => [
                self::COLOR_SCHEME_INHERIT,
                Settings::COLOR_SCHEME_LIGHT,
                Settings::COLOR_SCHEME_DARK,
                Settings::COLOR_SCHEME_AUTO,
            ],
        ];
        $rules[] = [['labelPosition'], 'in', 'range' => [self::LABEL_POSITION_TOP, self::LABEL_POSITION_LEFT]];
        $rules[] = [['appearance'], 'in', 'range' => [self::APPEARANCE_PLAIN, self::APPEARANCE_BORDERED]];
        // Same pattern Palette's own colour rule validates against - the
        // builder's native `<input type="color">` always posts a `#rrggbb`
        // value, so unlike Settings::setAttributes() there's no bare-hex
        // case here to normalize first.
        $rules[] = [
            ['paletteBrand', 'paletteSurface', 'paletteText', 'paletteBorder'],
            'match',
            'pattern' => '/^#[0-9a-f]{6}$/i',
            'skipOnEmpty' => true,
        ];
        $rules[] = [
            ['paletteRadius'],
            'in',
            'range' => [
                self::PALETTE_RADIUS_INHERIT,
                Palette::RADIUS_SHARP,
                Palette::RADIUS_SOFT,
                Palette::RADIUS_ROUND,
            ],
        ];
        $rules[] = [
            ['paletteButtonStyle'],
            'in',
            'range' => [
                self::PALETTE_BUTTON_STYLE_INHERIT,
                Palette::BUTTON_STYLE_FILLED,
                Palette::BUTTON_STYLE_OUTLINE,
                Palette::BUTTON_STYLE_SOFT,
            ],
        ];
        $rules[] = [
            ['redirectUrl'],
            'required',
            'when' => fn(self $model): bool => $model->successBehavior === self::SUCCESS_REDIRECT,
        ];
        $rules[] = [
            [
                'showReviewPage', 'storeSubmissions', 'enableSelfService', 'collectIp',
                'collectUserAgent', 'enableSaveAndResume', 'dataRetentionEnabled',
                'enableHoneypot', 'enableJsCheck', 'scheduleEnabled',
                'limitSubmissions',
            ],
            'boolean',
        ];
        // Reported on the storage switch: that's the control the Settings tab
        // shows the error under, and the one an author can flip to resolve it.
        $rules[] = [['storeSubmissions'], 'validateIntegrationsNeedStorage', 'skipOnEmpty' => false];
        // Only the close date carries the ordering error, so it's reported once
        // and lands next to the control the author has to move.
        $rules[] = [['scheduleCloseDate'], 'validateScheduleWindow'];
        // At least one - a cap of zero would shut the form to everyone, which is
        // what disabling it is for.
        $rules[] = [['maxSubmissions'], 'integer', 'min' => 1];
        $rules[] = [['dataRetentionDays'], 'integer', 'min' => 1, 'max' => 3650];
        // Capped: the minimum-time check exists to stop instant posts, not to
        // make a slow reader wait - a full minute is already generous.
        $rules[] = [['minSubmitTime'], 'integer', 'min' => 0, 'max' => 60];
        $rules[] = [
            ['spamAction'],
            'in',
            'range' => [self::SPAM_ACTION_FLAG, self::SPAM_ACTION_REJECT],
        ];
        // A captcha handle the plugin doesn't recognise is treated as "none"
        // rather than rejected, so a form exported from an install with an
        // extra captcha plugin still imports here.
        $rules[] = [['captcha'], 'string'];
        $rules[] = [
            ['progressIndicator'],
            'in',
            'range' => [self::PROGRESS_NONE, self::PROGRESS_BAR, self::PROGRESS_STEPS],
        ];
        // Capped rather than unbounded: the expiry is what stops a form
        // accumulating half-filled submissions forever, so "never" isn't on
        // offer.
        $rules[] = [
            ['saveAndResumeExpiryDays'],
            'integer',
            'min' => 1,
            'max' => 365,
        ];

        return $rules;
    }

    /**
     * A close date has to fall after the open date - a window that shuts before
     * it opens would leave the form permanently closed, which is never what the
     * author meant. Each bound stays independently optional; the check only
     * bites when both are set.
     */
    public function validateScheduleWindow(string $attribute): void
    {
        if (
            $this->scheduleOpenDate !== null
            && $this->scheduleCloseDate !== null
            && $this->scheduleCloseDate <= $this->scheduleOpenDate
        ) {
            $this->addError($attribute, Craft::t('formable', 'The close date must be after the open date.'));
        }
    }

    /**
     * A form that stores nothing has no submission for an integration to
     * deliver: {@see \bytesof\formable\services\Integrations::sendForSubmission()}
     * returns early without an ID, and the queued job, its retries and its
     * delivery log all key on one. Saving the combination would quietly turn
     * every integration off, so it's refused instead.
     *
     * Only integrations the plugin would actually run count - an entry for one
     * that was since deleted or globally disabled is dead config the builder no
     * longer lists, and refusing the save over something the author can't see
     * or switch off would be a trap. The service is consulted only once an
     * entry is switched on, so a notify-only form without integrations
     * validates without touching the database.
     */
    public function validateIntegrationsNeedStorage(string $attribute): void
    {
        if ($this->storeSubmissions) {
            return;
        }

        $switchedOn = array_map('strval', array_keys(array_filter(
            $this->integrations,
            static fn(mixed $config): bool => is_array($config) && ($config['enabled'] ?? false) === true,
        )));

        if ($switchedOn === []) {
            return;
        }

        $names = [];

        foreach (Plugin::getInstance()->getIntegrations()->getEnabledIntegrations() as $record) {
            if (in_array((string)$record->handle, $switchedOn, true)) {
                $names[] = (string)$record->name;
            }
        }

        if ($names === []) {
            return;
        }

        $this->addError($attribute, Craft::t(
            'formable',
            'Integrations only run for stored submissions. Turn on Store Submissions, or switch off: {names}.',
            ['names' => implode(', ', $names)],
        ));
    }

    /**
     * @return array<string, string>
     */
    public function attributeLabels(): array
    {
        return [
            'submitButtonLabel' => Craft::t('formable', 'Submit Button Label'),
            'submitMethod' => Craft::t('formable', 'Submission Method'),
            'successBehavior' => Craft::t('formable', 'On Success'),
            'successMessage' => Craft::t('formable', 'Success Message'),
            'successDetails' => Craft::t('formable', 'Success Details'),
            'redirectUrl' => Craft::t('formable', 'Redirect URL'),
            'errorMessage' => Craft::t('formable', 'Error Message'),
            'density' => Craft::t('formable', 'Form Density'),
            'cssClass' => Craft::t('formable', 'CSS Class'),
            'themeLevel' => Craft::t('formable', 'Theme Level'),
            'colorScheme' => Craft::t('formable', 'Colour Scheme'),
            'labelPosition' => Craft::t('formable', 'Label Position'),
            'appearance' => Craft::t('formable', 'Appearance'),
            'paletteBrand' => Craft::t('formable', 'Brand Colour'),
            'paletteSurface' => Craft::t('formable', 'Surface Colour'),
            'paletteText' => Craft::t('formable', 'Text Colour'),
            'paletteBorder' => Craft::t('formable', 'Border Colour'),
            'paletteRadius' => Craft::t('formable', 'Corner Radius'),
            'paletteButtonStyle' => Craft::t('formable', 'Button Style'),
            'scheduleEnabled' => Craft::t('formable', 'Schedule Availability'),
            'scheduleOpenDate' => Craft::t('formable', 'Open Date'),
            'scheduleCloseDate' => Craft::t('formable', 'Close Date'),
            'limitSubmissions' => Craft::t('formable', 'Limit Submissions'),
            'maxSubmissions' => Craft::t('formable', 'Maximum Submissions'),
            'closedMessage' => Craft::t('formable', 'Closed Message'),
            'showReviewPage' => Craft::t('formable', 'Show Review Page'),
            'reviewPageLabel' => Craft::t('formable', 'Review Page Heading'),
            'progressIndicator' => Craft::t('formable', 'Progress Indicator'),
            'nextButtonLabel' => Craft::t('formable', 'Next Button Label'),
            'backButtonLabel' => Craft::t('formable', 'Back Button Label'),
            'enableSaveAndResume' => Craft::t('formable', 'Save and Resume'),
            'saveAndResumeLabel' => Craft::t('formable', 'Save and Resume Label'),
            'saveAndResumeExpiryDays' => Craft::t('formable', 'Resume Link Expiry'),
            'storeSubmissions' => Craft::t('formable', 'Store Submissions'),
            'enableSelfService' => Craft::t('formable', 'Member Self-Service'),
            'collectIp' => Craft::t('formable', 'Collect IP Address'),
            'collectUserAgent' => Craft::t('formable', 'Collect User Agent'),
            'dataRetentionEnabled' => Craft::t('formable', 'Auto-delete Submissions'),
            'dataRetentionDays' => Craft::t('formable', 'Retention Period'),
            'templateOverridePath' => Craft::t('formable', 'Custom Template Path'),
            'enableHoneypot' => Craft::t('formable', 'Honeypot'),
            'enableJsCheck' => Craft::t('formable', 'JavaScript Check'),
            'minSubmitTime' => Craft::t('formable', 'Minimum Submit Time'),
            'spamAction' => Craft::t('formable', 'When Spam Is Detected'),
            'captcha' => Craft::t('formable', 'Captcha'),
        ];
    }

    /**
     * Declarative description of these settings, consumed by the builder's
     * Settings tab using the same control set as the field settings drawer.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function getSettingsSchema(): array
    {
        return [
            [
                'name' => 'submitButtonLabel',
                'type' => 'text',
                'label' => Craft::t('formable', 'Submit Button Label'),
                'required' => true,
                'group' => 'general',
            ],
            [
                'name' => 'submitMethod',
                'type' => 'select',
                'label' => Craft::t('formable', 'Submission Method'),
                'group' => 'general',
                'options' => [
                    [
                        'value' => self::SUBMIT_METHOD_PAGE,
                        'label' => Craft::t('formable', 'Page reload'),
                    ],
                    [
                        'value' => self::SUBMIT_METHOD_AJAX,
                        'label' => Craft::t('formable', 'AJAX'),
                    ],
                ],
            ],
            [
                'name' => 'successBehavior',
                'type' => 'select',
                'label' => Craft::t('formable', 'On Success'),
                'group' => 'general',
                'options' => [
                    [
                        'value' => self::SUCCESS_MESSAGE,
                        'label' => Craft::t('formable', 'Show a message'),
                    ],
                    [
                        'value' => self::SUCCESS_REDIRECT,
                        'label' => Craft::t('formable', 'Redirect'),
                    ],
                ],
            ],
            [
                'name' => 'successMessage',
                'type' => 'textarea',
                'label' => Craft::t('formable', 'Success Message'),
                'instructions' => Craft::t('formable', 'Twig is supported, e.g. {example}.', [
                    'example' => 'Thanks - your reference number is #{{ submission.id }}.',
                ]),
                'group' => 'general',
                'when' => ['successBehavior' => self::SUCCESS_MESSAGE],
            ],
            [
                'name' => 'successDetails',
                'type' => 'textarea',
                'label' => Craft::t('formable', 'Success Details'),
                'instructions' => Craft::t('formable', 'Optional - a smaller line under the success message, for what happens next or how long a reply takes. Twig is supported.'),
                'group' => 'general',
                'when' => ['successBehavior' => self::SUCCESS_MESSAGE],
            ],
            [
                'name' => 'redirectUrl',
                'type' => 'text',
                'label' => Craft::t('formable', 'Redirect URL'),
                'instructions' => Craft::t('formable', 'Twig is supported, e.g. {example}.', [
                    'example' => '/thanks?id={{ submission.id }}',
                ]),
                'group' => 'general',
                'when' => ['successBehavior' => self::SUCCESS_REDIRECT],
            ],
            [
                'name' => 'errorMessage',
                'type' => 'textarea',
                'label' => Craft::t('formable', 'Error Message'),
                'instructions' => Craft::t('formable', 'Shown above the form when validation fails.'),
                'group' => 'general',
            ],
            [
                'name' => 'density',
                'type' => 'select',
                'label' => Craft::t('formable', 'Form Density'),
                'instructions' => Craft::t('formable', 'How tightly the form is spaced. Needs the bundled stylesheet; ignored when it is turned off.'),
                'default' => self::DENSITY_COMFORTABLE,
                'group' => 'general',
                'options' => [
                    [
                        'value' => self::DENSITY_COMFORTABLE,
                        'label' => Craft::t('formable', 'Comfortable'),
                    ],
                    [
                        'value' => self::DENSITY_COMPACT,
                        'label' => Craft::t('formable', 'Compact'),
                    ],
                ],
            ],
            [
                'name' => 'appearance',
                'type' => 'select',
                'label' => Craft::t('formable', 'Appearance'),
                'instructions' => Craft::t('formable', 'The boxed/card treatment. Needs the bundled stylesheet.'),
                'default' => self::APPEARANCE_PLAIN,
                'group' => 'appearance',
                'options' => [
                    [
                        'value' => self::APPEARANCE_PLAIN,
                        'label' => Craft::t('formable', 'Plain'),
                    ],
                    [
                        'value' => self::APPEARANCE_BORDERED,
                        'label' => Craft::t('formable', 'Bordered'),
                    ],
                ],
            ],
            [
                'name' => 'colorScheme',
                'type' => 'select',
                'label' => Craft::t('formable', 'Colour Scheme'),
                'instructions' => Craft::t('formable', 'Overrides the plugin’s global colour scheme setting for this form only. Needs the bundled stylesheet.'),
                'default' => self::COLOR_SCHEME_INHERIT,
                'group' => 'appearance',
                'options' => [
                    [
                        'value' => self::COLOR_SCHEME_INHERIT,
                        'label' => Craft::t('formable', 'Use the global setting'),
                    ],
                    [
                        'value' => Settings::COLOR_SCHEME_LIGHT,
                        'label' => Craft::t('formable', 'Light'),
                    ],
                    [
                        'value' => Settings::COLOR_SCHEME_DARK,
                        'label' => Craft::t('formable', 'Dark'),
                    ],
                    [
                        'value' => Settings::COLOR_SCHEME_AUTO,
                        'label' => Craft::t('formable', 'Follow visitor’s device'),
                    ],
                ],
            ],
            [
                'name' => 'labelPosition',
                'type' => 'select',
                'label' => Craft::t('formable', 'Label Position'),
                'instructions' => Craft::t('formable', 'Where a field’s label sits relative to its input. Needs the bundled stylesheet.'),
                'default' => self::LABEL_POSITION_TOP,
                'group' => 'appearance',
                'options' => [
                    [
                        'value' => self::LABEL_POSITION_TOP,
                        'label' => Craft::t('formable', 'Above the field'),
                    ],
                    [
                        'value' => self::LABEL_POSITION_LEFT,
                        'label' => Craft::t('formable', 'To the left of the field'),
                    ],
                ],
            ],
            [
                'name' => 'themeLevel',
                'type' => 'select',
                'label' => Craft::t('formable', 'Theme Level'),
                'instructions' => Craft::t('formable', 'Overrides the plugin’s global stylesheet setting for this form only.'),
                'default' => self::THEME_LEVEL_INHERIT,
                'group' => 'appearance',
                'options' => [
                    [
                        'value' => self::THEME_LEVEL_INHERIT,
                        'label' => Craft::t('formable', 'Use the global setting'),
                    ],
                    [
                        'value' => Settings::THEME_LEVEL_CONTRACT,
                        'label' => Craft::t('formable', 'Contract only'),
                    ],
                    [
                        'value' => Settings::THEME_LEVEL_RESET,
                        'label' => Craft::t('formable', 'Reset only'),
                    ],
                    [
                        'value' => Settings::THEME_LEVEL_DEFAULT,
                        'label' => Craft::t('formable', 'Default theme'),
                    ],
                ],
            ],
            [
                'name' => 'cssClass',
                'type' => 'text',
                'label' => Craft::t('formable', 'CSS Class'),
                'instructions' => Craft::t('formable', 'Added to the rendered form element, alongside the plugin’s own classes.'),
                'group' => 'appearance',
            ],
            [
                'name' => 'paletteBrand',
                'type' => 'color',
                'label' => Craft::t('formable', 'Brand Colour'),
                'instructions' => Craft::t('formable', 'Overrides the plugin’s global brand colour for this form only. Leave as the default to use the global setting.'),
                'group' => 'appearance',
                'proOnly' => true,
            ],
            [
                'name' => 'paletteSurface',
                'type' => 'color',
                'label' => Craft::t('formable', 'Surface Colour'),
                'instructions' => Craft::t('formable', 'Overrides the plugin’s global surface colour for this form only. Only used with a light or dark colour scheme, not the visitor’s device.'),
                'group' => 'appearance',
                'proOnly' => true,
                'when' => ['colorScheme' => [Settings::COLOR_SCHEME_LIGHT, Settings::COLOR_SCHEME_DARK]],
            ],
            [
                'name' => 'paletteText',
                'type' => 'color',
                'label' => Craft::t('formable', 'Text Colour'),
                'instructions' => Craft::t('formable', 'Overrides the plugin’s global text colour for this form only. Only used with a light or dark colour scheme, not the visitor’s device.'),
                'group' => 'appearance',
                'proOnly' => true,
                'when' => ['colorScheme' => [Settings::COLOR_SCHEME_LIGHT, Settings::COLOR_SCHEME_DARK]],
            ],
            [
                'name' => 'paletteBorder',
                'type' => 'color',
                'label' => Craft::t('formable', 'Border Colour'),
                'instructions' => Craft::t('formable', 'Overrides the plugin’s global border colour for this form only. Only used with a light or dark colour scheme, not the visitor’s device.'),
                'group' => 'appearance',
                'proOnly' => true,
                'when' => ['colorScheme' => [Settings::COLOR_SCHEME_LIGHT, Settings::COLOR_SCHEME_DARK]],
            ],
            [
                'name' => 'paletteRadius',
                'type' => 'select',
                'label' => Craft::t('formable', 'Corner Radius'),
                'instructions' => Craft::t('formable', 'Overrides the plugin’s global corner radius for this form only.'),
                'default' => self::PALETTE_RADIUS_INHERIT,
                'group' => 'appearance',
                'proOnly' => true,
                'options' => [
                    [
                        'value' => self::PALETTE_RADIUS_INHERIT,
                        'label' => Craft::t('formable', 'Use the global setting'),
                    ],
                    [
                        'value' => Palette::RADIUS_SHARP,
                        'label' => Craft::t('formable', 'Sharp'),
                    ],
                    [
                        'value' => Palette::RADIUS_SOFT,
                        'label' => Craft::t('formable', 'Soft'),
                    ],
                    [
                        'value' => Palette::RADIUS_ROUND,
                        'label' => Craft::t('formable', 'Round'),
                    ],
                ],
            ],
            [
                'name' => 'paletteButtonStyle',
                'type' => 'select',
                'label' => Craft::t('formable', 'Button Style'),
                'instructions' => Craft::t('formable', 'Overrides the plugin’s global button style for this form only.'),
                'default' => self::PALETTE_BUTTON_STYLE_INHERIT,
                'group' => 'appearance',
                'proOnly' => true,
                'options' => [
                    [
                        'value' => self::PALETTE_BUTTON_STYLE_INHERIT,
                        'label' => Craft::t('formable', 'Use the global setting'),
                    ],
                    [
                        'value' => Palette::BUTTON_STYLE_FILLED,
                        'label' => Craft::t('formable', 'Filled'),
                    ],
                    [
                        'value' => Palette::BUTTON_STYLE_OUTLINE,
                        'label' => Craft::t('formable', 'Outline'),
                    ],
                    [
                        'value' => Palette::BUTTON_STYLE_SOFT,
                        'label' => Craft::t('formable', 'Soft'),
                    ],
                ],
            ],
            [
                'name' => 'scheduleEnabled',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Schedule Availability'),
                'instructions' => Craft::t('formable', 'Only accept submissions within a date window. Outside it, the form shows its closed message. (Pro)'),
                'group' => 'availability',
                'proOnly' => true,
            ],
            [
                'name' => 'scheduleOpenDate',
                'type' => 'datetime',
                'label' => Craft::t('formable', 'Open Date'),
                'instructions' => Craft::t('formable', 'When the form starts accepting submissions. Leave empty to open immediately.'),
                'group' => 'availability',
                'proOnly' => true,
                'when' => ['scheduleEnabled' => true],
            ],
            [
                'name' => 'scheduleCloseDate',
                'type' => 'datetime',
                'label' => Craft::t('formable', 'Close Date'),
                'instructions' => Craft::t('formable', 'When the form stops accepting submissions. Leave empty to stay open indefinitely.'),
                'group' => 'availability',
                'proOnly' => true,
                'when' => ['scheduleEnabled' => true],
            ],
            [
                'name' => 'limitSubmissions',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Limit Submissions'),
                'instructions' => Craft::t('formable', 'Stop accepting submissions once the form reaches a total. Spam and unfinished submissions don’t count. (Pro)'),
                'group' => 'availability',
                'proOnly' => true,
            ],
            [
                'name' => 'maxSubmissions',
                'type' => 'number',
                'label' => Craft::t('formable', 'Maximum Submissions'),
                'instructions' => Craft::t('formable', 'The total to accept before the form closes.'),
                'min' => 1,
                'group' => 'availability',
                'proOnly' => true,
                'when' => ['limitSubmissions' => true],
            ],
            [
                'name' => 'closedMessage',
                'type' => 'textarea',
                'label' => Craft::t('formable', 'Closed Message'),
                'instructions' => Craft::t('formable', 'Shown in place of the form when it isn’t accepting submissions - disabled, or outside its window. Twig is supported.'),
                'group' => 'availability',
            ],
            [
                'name' => 'progressIndicator',
                'type' => 'select',
                'label' => Craft::t('formable', 'Progress Indicator'),
                'instructions' => Craft::t('formable', 'How a multi-page form shows submitters where they are.'),
                'group' => 'pages',
                'options' => [
                    [
                        'value' => self::PROGRESS_STEPS,
                        'label' => Craft::t('formable', 'Numbered steps'),
                    ],
                    [
                        'value' => self::PROGRESS_BAR,
                        'label' => Craft::t('formable', 'Progress bar'),
                    ],
                    [
                        'value' => self::PROGRESS_NONE,
                        'label' => Craft::t('formable', 'None'),
                    ],
                ],
            ],
            [
                'name' => 'nextButtonLabel',
                'type' => 'text',
                'label' => Craft::t('formable', 'Next Button Label'),
                'required' => true,
                'group' => 'pages',
            ],
            [
                'name' => 'backButtonLabel',
                'type' => 'text',
                'label' => Craft::t('formable', 'Back Button Label'),
                'required' => true,
                'group' => 'pages',
            ],
            [
                'name' => 'showReviewPage',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Show Review Page'),
                'instructions' => Craft::t('formable', 'Multi-page forms only - let submitters review their answers before the final submit.'),
                'group' => 'pages',
            ],
            [
                'name' => 'reviewPageLabel',
                'type' => 'text',
                'label' => Craft::t('formable', 'Review Page Heading'),
                'group' => 'pages',
                'when' => ['showReviewPage' => true],
            ],
            [
                'name' => 'enableSaveAndResume',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Save and Resume'),
                'instructions' => Craft::t('formable', 'Let submitters save a part-filled form and finish it later from an emailed link.'),
                'group' => 'pages',
                'proOnly' => true,
            ],
            [
                'name' => 'saveAndResumeLabel',
                'type' => 'text',
                'label' => Craft::t('formable', 'Save and Resume Label'),
                'group' => 'pages',
                'when' => ['enableSaveAndResume' => true],
                'proOnly' => true,
            ],
            [
                'name' => 'saveAndResumeExpiryDays',
                'type' => 'number',
                'label' => Craft::t('formable', 'Resume Link Expiry'),
                'instructions' => Craft::t('formable', 'Days a saved form stays resumable before it is deleted.'),
                'min' => 1,
                'max' => 365,
                'group' => 'pages',
                'when' => ['enableSaveAndResume' => true],
                'proOnly' => true,
            ],
            [
                'name' => 'templateOverridePath',
                'type' => 'text',
                'label' => Craft::t('formable', 'Custom Template Path'),
                'instructions' => Craft::t('formable', 'A folder in your templates directory, e.g. {example}. Templates found there override the plugin’s; the rest fall back.', [
                    'example' => '_forms/contact',
                ]),
                'group' => 'general',
            ],
            [
                'name' => 'storeSubmissions',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Store Submissions'),
                'instructions' => Craft::t('formable', 'Turn off to send notifications without keeping a copy in the control panel. Integrations need stored submissions, so they are unavailable while this is off.'),
                'group' => 'privacy',
            ],
            [
                'name' => 'enableSelfService',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Member Self-Service'),
                'instructions' => Craft::t('formable', 'Let a logged-in submitter edit or delete their own completed submissions from the front end.'),
                'group' => 'privacy',
                'proOnly' => true,
            ],
            [
                'name' => 'collectIp',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Collect IP Address'),
                'group' => 'privacy',
            ],
            [
                'name' => 'collectUserAgent',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Collect User Agent'),
                'group' => 'privacy',
            ],
            [
                'name' => 'dataRetentionEnabled',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Auto-delete Submissions'),
                'instructions' => Craft::t('formable', 'Permanently delete completed submissions once they reach the retention period.'),
                'group' => 'privacy',
                'proOnly' => true,
            ],
            [
                'name' => 'dataRetentionDays',
                'type' => 'number',
                'label' => Craft::t('formable', 'Retention Period'),
                'instructions' => Craft::t('formable', 'Days a submission is kept before it is deleted.'),
                'min' => 1,
                'max' => 3650,
                'group' => 'privacy',
                'when' => ['dataRetentionEnabled' => true],
                'proOnly' => true,
            ],
            [
                'name' => 'enableHoneypot',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Honeypot'),
                'instructions' => Craft::t('formable', 'Add a decoy field that real visitors never see but simple bots fill in.'),
                'group' => 'spam',
            ],
            [
                'name' => 'enableJsCheck',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'JavaScript Check'),
                'instructions' => Craft::t('formable', 'Require a token only the browser can set. Flags submissions made with JavaScript disabled.'),
                'group' => 'spam',
            ],
            [
                'name' => 'minSubmitTime',
                'type' => 'number',
                'label' => Craft::t('formable', 'Minimum Submit Time'),
                'instructions' => Craft::t('formable', 'Treat a form submitted faster than this many seconds as spam. Set to 0 to turn off.'),
                'min' => 0,
                'max' => 60,
                'group' => 'spam',
            ],
            [
                'name' => 'captcha',
                'type' => 'select',
                'label' => Craft::t('formable', 'Captcha'),
                'instructions' => Craft::t('formable', 'Add a captcha challenge on the final page. Configure provider keys in the plugin settings.'),
                'group' => 'spam',
                'options' => self::captchaOptions(),
                'proOnly' => true,
            ],
            [
                'name' => 'spamAction',
                'type' => 'select',
                'label' => Craft::t('formable', 'When Spam Is Detected'),
                'group' => 'spam',
                'options' => [
                    [
                        'value' => self::SPAM_ACTION_FLAG,
                        'label' => Craft::t('formable', 'Keep it, flagged for review'),
                    ],
                    [
                        'value' => self::SPAM_ACTION_REJECT,
                        'label' => Craft::t('formable', 'Discard it'),
                    ],
                ],
            ],
        ];
    }

    /**
     * The captcha picker's options: “None”, then one per provider.
     *
     * The provider list is hard-coded rather than pulled from the captcha
     * registry so the schema stays computable without a booted plugin (the
     * builder asks for it, and so do unit tests). {@see CaptchaOptionsTest}
     * keeps the two in step.
     *
     * @return array<int, array<string, string>>
     */
    public static function captchaOptions(): array
    {
        return [
            ['value' => self::CAPTCHA_NONE, 'label' => Craft::t('formable', 'None')],
            ['value' => 'recaptchaV2', 'label' => Craft::t('formable', 'reCAPTCHA v2')],
            ['value' => 'recaptchaV3', 'label' => Craft::t('formable', 'reCAPTCHA v3')],
            ['value' => 'hcaptcha', 'label' => Craft::t('formable', 'hCaptcha')],
            ['value' => 'turnstile', 'label' => Craft::t('formable', 'Cloudflare Turnstile')],
        ];
    }
}
