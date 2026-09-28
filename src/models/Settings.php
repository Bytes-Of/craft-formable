<?php

declare(strict_types=1);

namespace bytesof\formable\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\validators\ColorValidator;
use DateTimeInterface;

/**
 * @api
 */
final class Settings extends Model
{
    /**
     * How long after a digest goes out the scheduler refuses to send another,
     * so an hourly cron that fires more than once inside the scheduled hour -
     * or a cache that briefly forgets the last send - can't double-post the
     * week's digest. Six days: comfortably shorter than the weekly cadence, and
     * long enough to swallow any same-window retry.
     */
    public const DIGEST_RESEND_GUARD_SECONDS = 6 * 24 * 60 * 60;

    /**
     * No stylesheet at all. Site must supply its own
     * `.formable-visually-hidden` rule, since the markup relies on one.
     */
    public const THEME_LEVEL_NONE = 'none';

    /**
     * The accessibility contract alone - the visually-hidden utility,
     * `[hidden]` state and a tokenless focus-ring shape - no layout, no
     * paint. For a headless or heavily-Tailwind site that wants none of
     * Formable's layout opinions but still needs the markup's a11y contract
     * honoured.
     */
    public const THEME_LEVEL_CONTRACT = 'contract';

    /**
     * {@see THEME_LEVEL_CONTRACT} plus layout - flex/grid, the
     * disabled/submitting state hooks - with no colour, typography or
     * radius. For a site building its own design system on top of Formable's
     * markup.
     */
    public const THEME_LEVEL_RESET = 'reset';

    /**
     * Reset plus a complete visual theme: colour, typography, spacing,
     * radius, every component painted. The `--formable-*` custom properties
     * it paints with are free to override from the site's own stylesheet.
     */
    public const THEME_LEVEL_DEFAULT = 'default';

    /**
     * Pin the palette to light regardless of the visitor's OS preference or
     * any ancestor forcing dark - see {@see $colorScheme}.
     */
    public const COLOR_SCHEME_LIGHT = 'light';

    /**
     * Pin the palette to dark regardless of the visitor's OS preference or
     * any ancestor forcing light.
     */
    public const COLOR_SCHEME_DARK = 'dark';

    /**
     * Follow `prefers-color-scheme` (or an ancestor's forced
     * `data-formable-scheme`), and paint the form's own surface so it stays
     * legible against a host background that doesn't move with it.
     */
    public const COLOR_SCHEME_AUTO = 'auto';

    /**
     * How much of the plugin's optional stylesheet rendered forms pull in.
     *
     * `default` since 1.1: a rendered form is what a buyer judges the plugin
     * by, and an unstyled one reads as broken, so a form ships looking
     * finished. The theme paints only with overridable `--formable-*` custom
     * properties and sits in its own cascade layer, so a site's own rules win
     * without a specificity fight; a site that wants the markup bare sets
     * `none`. Decision record 0032 in the repo covers why the default moved.
     *
     * {@see $includeTheme} is the pre-1.1 boolean this replaced - kept as a
     * virtual property so an existing `config/formable.php` with
     * `'includeTheme' => true` still works, coerced to `default`.
     */
    public string $themeLevel = self::THEME_LEVEL_DEFAULT;

    /**
     * Back-compat alias for {@see $themeLevel}: `true` reads/writes
     * `default`, `false` reads/writes `none`. Not itself validated or shown
     * on the settings screen - `themeLevel` is the real setting.
     */
    public function getIncludeTheme(): bool
    {
        return $this->themeLevel !== self::THEME_LEVEL_NONE;
    }

    public function setIncludeTheme(bool $value): void
    {
        $this->themeLevel = $value ? self::THEME_LEVEL_DEFAULT : self::THEME_LEVEL_NONE;
    }

    public function init(): void
    {
        parent::init();

        if (!isset($this->palette)) {
            $this->palette = new Palette();
        }
    }

    /**
     * `Palette` is itself a `Model`, not a scalar {@see \craft\helpers\Typecast}
     * knows how to coerce an array into, but that's exactly the shape a posted
     * `palette` value arrives in - both the plugin-settings save path and the
     * bootstrap load from project config call this with the raw stored/posted
     * array and `$safeOnly = false`. Hydrate it onto the existing
     * {@see $palette} instance directly and let the parent handle every other
     * attribute as usual.
     *
     * A colour posted through `forms.colorField()` also arrives without its
     * leading `#` - that control's text input never carries one - so each one
     * is normalized the same way `craft\models\ImageTransform::$fill` is for
     * the identical control, before {@see Palette}'s own stricter,
     * `#`-required pattern validates it.
     *
     * @param array<string, mixed> $values
     */
    public function setAttributes($values, $safeOnly = true): void
    {
        if (is_array($values) && is_array($values['palette'] ?? null)) {
            $paletteValues = $values['palette'];
            unset($values['palette']);

            foreach (['brand', 'surface', 'text', 'border'] as $colorAttribute) {
                if (!empty($paletteValues[$colorAttribute])) {
                    $paletteValues[$colorAttribute] = ColorValidator::normalizeColor($paletteValues[$colorAttribute]);
                }
            }

            $this->palette->setAttributes($paletteValues, false);
        }

        parent::setAttributes($values, $safeOnly);
    }

    /**
     * Which palette a rendered form paints with, emitted as
     * `data-formable-scheme` on the `<form>` (internal/decisions/0071).
     *
     * `light` by default - a form dropped onto an unknown, presumed-light
     * host page never repaints its text near-white out from under a dark OS
     * preference (internal/decisions/0033's H2). `dark` is the equivalent
     * pin the other way, for a site embedding a form into a section it knows
     * is dark. `auto` restores the pre-1.4 behaviour of following
     * `prefers-color-scheme`, but now also paints the form's own surface, so
     * it stays self-contained on whatever background it lands on rather than
     * assuming the host repaints along with it.
     */
    public string $colorScheme = self::COLOR_SCHEME_LIGHT;

    /**
     * The site-wide default brand palette a rendered form paints with, absent
     * a per-render option or a per-form override
     * ({@see \bytesof\formable\services\Rendering::resolvePalette()}'s
     * precedence, mirroring {@see $colorScheme}'s). Unset
     * ({@see Palette::isEmpty()}) by default, so an install with no palette
     * chosen renders byte-identical to one with no palette feature at all -
     * internal/decisions/0089.
     */
    public Palette $palette;

    /**
     * Email a weekly activity digest - new submissions and spam counts per form
     * for the trailing seven days - to a fixed list of recipients (Pro).
     *
     * Off by default. The plugin owns the schedule ({@see $weeklyDigestDay} and
     * {@see $weeklyDigestHour}); the send itself is driven by the
     * `formable/digest/run` console command wired to the server's cron, which
     * self-gates through {@see isDigestDue()} so running it hourly sends at most
     * one digest a week.
     */
    public bool $weeklyDigestEnabled = false;

    /**
     * Who the digest goes to - an address list in the same one-per-line / comma
     * form as a notification's recipients, parsed by
     * {@see \bytesof\formable\services\Notifications::parseAddressList()}.
     */
    public string $weeklyDigestRecipients = '';

    /**
     * The day of the week the digest goes out, as an ISO-8601 weekday
     * (1 = Monday … 7 = Sunday) so it lines up with `date('N')`.
     */
    public int $weeklyDigestDay = 1;

    /**
     * The hour of {@see $weeklyDigestDay} the digest goes out, 0–23 in the
     * site's own timezone.
     */
    public int $weeklyDigestHour = 8;

    /**
     * Skip a week with nothing to report rather than emailing an empty digest.
     * On by default - a "0 new submissions" email every week trains recipients
     * to ignore it.
     */
    public bool $weeklyDigestSkipWhenEmpty = true;

    /**
     * The From address and name the digest goes out under, read only by
     * {@see \bytesof\formable\services\Digest}. Blank - the default - sends it
     * from Craft's own system address.
     *
     * These are deliberately *not* a plugin-wide sender: notification emails
     * are addressed by {@see \craft\helpers\App::mailSettings()} like every
     * other Craft mail, and a second global sender that silently only applied
     * to one email was the confusion this pair used to cause.
     */
    public string $senderEmail = '';
    public string $senderName = '';

    /**
     * Whether permanently deleting a submission also deletes the files that
     * were uploaded with it. On by default: an erasure request that leaves the
     * submitter's CV sitting in a volume hasn't been fulfilled. Only assets
     * Formable's own upload pipeline created are ever touched - see
     * {@see \bytesof\formable\services\Uploads::deleteOrphanedUploads()}.
     */
    public bool $deleteUploadsWithSubmissions = true;

    /**
     * How many days notification and integration log rows are kept before
     * garbage collection removes them. 0 keeps them indefinitely. The logs
     * hold recipients, subjects and failed payloads - personal data with no
     * retention of its own otherwise.
     */
    public int $deliveryLogRetentionDays = 90;

    /**
     * Hostnames, addresses and CIDR ranges integrations may call even though
     * they are private, one per line. Empty by default: an integration URL that
     * resolves to a private, loopback or link-local address is refused, so a
     * delegated `manageIntegrations` holder can't aim the server at the internal
     * network. Lives here rather than on an integration because Settings is
     * admin-only, which the permission that edits a URL is not.
     */
    public string $integrationAllowedHosts = '';

    /**
     * The name the honeypot input is rendered under.
     *
     * Deliberately mundane - `email2` reads to a bot's field-filler like a
     * second address input worth completing, which is exactly the trap. Left
     * configurable so a site can change it if a spam run learns the default.
     */
    public string $honeypotFieldName = 'formable_hp_email';

    /**
     * Words that mark a submission as spam if any of its text values contains
     * one, entered one per line. Matched case-insensitively as substrings, so
     * `casino` catches `Casino` and `online-casino` alike.
     */
    public string $spamKeywords = '';

    /**
     * IP addresses whose submissions are always treated as spam, one per line.
     * A `*` stands in for any part of an address (`203.0.113.*`), so a whole
     * noisy block can be shut out with one line.
     */
    public string $blockedIps = '';

    /**
     * How many times one IP address may save an unfinished form for later on
     * one form inside {@see $rateLimitWindow} seconds before further attempts
     * are quietly dropped - answered exactly as a saved one, so a bot learns
     * nothing from being throttled.
     *
     * Save-and-resume writes a row and sends an email on an anonymous
     * submitter's behalf, so an unblocked bot cannot loop it into a storage
     * and sender-reputation problem. 0 turns the limit off. {@see
     * $submitRateLimitMax} is the equivalent for the submit pipeline, counted
     * separately.
     */
    public int $rateLimitMax = 5;

    /**
     * The window, in seconds, {@see $rateLimitMax} is counted over. The counter
     * is a fixed window: it opens on the first hit and lapses this many seconds
     * after the last accepted one.
     */
    public int $rateLimitWindow = 600;

    /**
     * How many times one IP address may complete an anonymous submit on one
     * form - a row, every notification, every integration - inside
     * {@see $submitRateLimitWindow} seconds before further attempts are
     * quietly dropped and answered exactly as a completed one. Counted apart
     * from {@see $rateLimitMax}, since a submitter who has already parked and
     * resumed a form should not spend from the same budget they need to
     * actually finish it. Higher than the save default because a genuine
     * multi-submission source - a shared office IP, a kiosk - is far more
     * plausible here than against save-and-resume's much smaller, deliberate
     * action. 0 turns the limit off.
     */
    public int $submitRateLimitMax = 30;

    /**
     * The window, in seconds, {@see $submitRateLimitMax} is counted over.
     */
    public int $submitRateLimitWindow = 600;

    /**
     * Per-provider credentials. A captcha renders and verifies only once both
     * its keys are set, so an empty pair simply means "not configured" - the
     * secret keys accept `$VAR` env references like every other Craft secret.
     */
    public string $recaptchaV2SiteKey = '';
    public string $recaptchaV2SecretKey = '';
    public string $recaptchaV3SiteKey = '';
    public string $recaptchaV3SecretKey = '';

    /**
     * The score below which reCAPTCHA v3 calls a request a bot. Google's own
     * default; 0 passes everything, 1 passes nothing.
     */
    public float $recaptchaV3Threshold = 0.5;

    public string $hcaptchaSiteKey = '';
    public string $hcaptchaSecretKey = '';
    public string $turnstileSiteKey = '';
    public string $turnstileSecretKey = '';

    /**
     * Whether the weekly digest is due to go out at the given moment.
     *
     * Pure and edition-agnostic - it only answers the schedule question, the
     * same way {@see FormSettings::isWithinScheduleWindow()} answers the date
     * one, so it can be unit tested without a booted plugin. The Pro gate and
     * the actual send live in {@see \bytesof\formable\services\Digest}.
     *
     * True only during the configured day-and-hour, and only if the last digest
     * went out long enough ago ({@see DIGEST_RESEND_GUARD_SECONDS}) - so an
     * hourly cron fires the digest once when the hour comes round and never
     * twice, and a missed hour simply waits for next week rather than firing
     * late.
     *
     * @internal
     */
    public function isDigestDue(DateTimeInterface $now, ?DateTimeInterface $lastSent): bool
    {
        if (!$this->weeklyDigestEnabled) {
            return false;
        }

        if ((int)$now->format('N') !== $this->weeklyDigestDay) {
            return false;
        }

        if ((int)$now->format('G') !== $this->weeklyDigestHour) {
            return false;
        }

        if ($lastSent !== null && ($now->getTimestamp() - $lastSent->getTimestamp()) < self::DIGEST_RESEND_GUARD_SECONDS) {
            return false;
        }

        return true;
    }

    /**
     * Secret keys resolve `$ENV_VAR` references, matching how Craft stores
     * every other third-party credential.
     *
     * @return array<string, mixed>
     */
    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => [
                    'recaptchaV2SecretKey',
                    'recaptchaV3SecretKey',
                    'hcaptchaSecretKey',
                    'turnstileSecretKey',
                ],
            ],
        ];
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [
                ['themeLevel'],
                'in',
                'range' => [
                    self::THEME_LEVEL_NONE,
                    self::THEME_LEVEL_CONTRACT,
                    self::THEME_LEVEL_RESET,
                    self::THEME_LEVEL_DEFAULT,
                ],
            ],
            [
                ['colorScheme'],
                'in',
                'range' => [
                    self::COLOR_SCHEME_LIGHT,
                    self::COLOR_SCHEME_DARK,
                    self::COLOR_SCHEME_AUTO,
                ],
            ],
            // Delegates to Palette's own rules and copies its errors back
            // under "palette.<attribute>" so _settings.twig can show each
            // colour field's error against that field specifically.
            [
                ['palette'],
                function(string $attribute): void {
                    if (!$this->palette->validate()) {
                        foreach ($this->palette->getErrors() as $paletteAttribute => $errors) {
                            foreach ($errors as $error) {
                                $this->addError("$attribute.$paletteAttribute", $error);
                            }
                        }
                    }
                },
            ],
            [['senderEmail'], 'email'],
            [['senderName'], 'string', 'max' => 255],
            [['weeklyDigestEnabled', 'weeklyDigestSkipWhenEmpty'], 'boolean'],
            [['weeklyDigestRecipients'], 'string'],
            // Required only once the digest is switched on - an empty list would
            // send it nowhere.
            [
                ['weeklyDigestRecipients'],
                'required',
                'when' => static fn(self $model): bool => $model->weeklyDigestEnabled,
            ],
            [['weeklyDigestDay'], 'integer', 'min' => 1, 'max' => 7],
            [['weeklyDigestHour'], 'integer', 'min' => 0, 'max' => 23],
            [['honeypotFieldName'], 'string', 'max' => 255],
            // Anything a browser will post under a name - no spaces or brackets,
            // which would either not round-trip or turn into an array key.
            [['honeypotFieldName'], 'match', 'pattern' => '/^[A-Za-z0-9_\-]+$/'],
            [['honeypotFieldName'], 'required'],
            [['spamKeywords', 'blockedIps', 'integrationAllowedHosts'], 'string'],
            [['rateLimitMax'], 'integer', 'min' => 0],
            [['rateLimitWindow'], 'integer', 'min' => 1],
            [['submitRateLimitMax'], 'integer', 'min' => 0],
            [['submitRateLimitWindow'], 'integer', 'min' => 1],
            [['deleteUploadsWithSubmissions'], 'boolean'],
            [['deliveryLogRetentionDays'], 'integer', 'min' => 0],
            [
                [
                    'recaptchaV2SiteKey', 'recaptchaV2SecretKey',
                    'recaptchaV3SiteKey', 'recaptchaV3SecretKey',
                    'hcaptchaSiteKey', 'hcaptchaSecretKey',
                    'turnstileSiteKey', 'turnstileSecretKey',
                ],
                'string',
            ],
            [['recaptchaV3Threshold'], 'number', 'min' => 0, 'max' => 1],
        ];
    }
}
