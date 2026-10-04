<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\models\Settings;
use bytesof\formable\models\SpamContext;
use bytesof\formable\models\SpamReason;
use bytesof\formable\models\SpamResult;
use bytesof\formable\Plugin;
use Craft;
use yii\base\Component;
use yii\caching\CacheInterface;

/**
 * The spam gate the submission pipeline runs a completed submission through.
 *
 * It gathers a form's own checks (honeypot, JavaScript token, minimum submit
 * time, captcha) and the site-wide blocklists (keywords, IP addresses) into one
 * verdict. The cheap local checks run first and the network-bound captcha last,
 * and the first check to fire wins - there's no value in asking a provider
 * about a request a honeypot already gave away.
 *
 * The subset that is already conclusive on a half-filled form is split out as
 * {@see evaluateEarly()}, which is what a multi-page form's Next and Save posts
 * are weighed against so an obvious bot never gets anything stored or emailed
 * on its behalf.
 *
 * The decision logic that doesn't need a booted app - keyword and IP matching,
 * the time comparison - is factored into pure helpers so it can be unit tested
 * the way a hand-rolled spam post would probe it.
 *
 * @internal
 */
final class Spam extends Component
{
    /**
     * The hidden field carrying the signed render timestamp, checked against
     * the minimum submit time. Signed, so a bot can't just post a stale value.
     */
    public const TIME_FIELD = '__frmt';

    /**
     * The hidden field the front-end bundle stamps to prove JavaScript ran.
     * Empty on arrival; a value means a browser filled it in.
     */
    public const JS_FIELD = '__frmjs';

    /**
     * The cache key prefix the rate-limit counters live under.
     */
    /** Far past any real fill-in session; older than this reads as a stale cached page. */
    private const TIME_TOKEN_MAX_AGE = 86400;

    private const RATE_LIMIT_PREFIX = 'formable.spam.rate-limit';

    private ?Settings $_settings = null;

    private ?Captchas $_captchas = null;

    private ?CacheInterface $_cache = null;

    public function setSettings(Settings $settings): void
    {
        $this->_settings = $settings;
    }

    public function getSettings(): Settings
    {
        return $this->_settings ??= Plugin::getInstance()->getSettings();
    }

    public function setCaptchas(Captchas $captchas): void
    {
        $this->_captchas = $captchas;
    }

    public function getCaptchas(): Captchas
    {
        return $this->_captchas ??= Plugin::getInstance()->getCaptchas();
    }

    public function setCache(CacheInterface $cache): void
    {
        $this->_cache = $cache;
    }

    public function getCache(): CacheInterface
    {
        return $this->_cache ??= Craft::$app->getCache();
    }

    /**
     * Weighs a submission against every enabled check, returning the first that
     * fires or a clean verdict.
     */
    public function evaluate(Form $form, Submission $submission, SpamContext $context): SpamResult
    {
        $early = $this->evaluateEarly($form, $context);

        if ($early->isSpam) {
            return $early;
        }

        $settings = $form->getFormSettings();
        $global = $this->getSettings();

        if ($settings->minSubmitTime > 0) {
            $elapsed = $this->elapsedSeconds($context->timeToken);

            // A missing or tampered token is as suspicious as a too-fast one -
            // a genuine render always carries a valid one.
            if ($elapsed === null || $this->isTooFast($elapsed, $settings->minSubmitTime)) {
                return SpamResult::spam(SpamReason::TooFast);
            }
        }

        $keywords = $this->parseList($global->spamKeywords);

        if ($keywords !== [] && $this->matchesKeyword($this->collectText($submission), $keywords) !== null) {
            return SpamResult::spam(SpamReason::Keyword);
        }

        $captcha = $this->getCaptchas()->getCaptchaForForm($form);

        if ($captcha !== null && !$captcha->verify($context->captchaToken, $context->ipAddress)) {
            return SpamResult::spam(SpamReason::Captcha);
        }

        return SpamResult::clean();
    }

    /**
     * The checks that are already conclusive while a form is still being
     * filled in - what a multi-page form's Next and Save posts are weighed
     * against, so nothing is stored or emailed on behalf of a request that has
     * already given itself away.
     *
     * Only the signals that mean the same thing mid-flow as at the end belong
     * here. The honeypot and JS-token fields live in the form shell and are
     * posted with every step, and an IP is an IP. The minimum-submit-time and
     * keyword checks deliberately stay out: the timer measures filling in the
     * whole form, not one page of it, and the keyword blocklist would be
     * scanning answers the submitter hasn't finished giving - both would turn
     * a slow first page or an unfinished sentence into a false positive.
     *
     * No submission is needed, so this can run before there is anything worth
     * calling a submission.
     */
    public function evaluateEarly(Form $form, SpamContext $context): SpamResult
    {
        $settings = $form->getFormSettings();

        if ($settings->enableHoneypot && $this->isFilled($context->honeypot)) {
            return SpamResult::spam(SpamReason::Honeypot);
        }

        if ($settings->enableJsCheck && !$this->isFilled($context->jsToken)) {
            return SpamResult::spam(SpamReason::Js);
        }

        if ($this->isIpBlocked($context->ipAddress, $this->parseList($this->getSettings()->blockedIps))) {
            return SpamResult::spam(SpamReason::Ip);
        }

        return SpamResult::clean();
    }

    /**
     * Whether a bucket has spent its allowance for the current window.
     *
     * A fixed-window counter in Craft's cache. The first hit in a window writes
     * a key set to expire when the window closes; each later hit bumps the
     * count, and once it reaches $max the bucket is limited until the key
     * lapses - which, because a denied hit never touches the key, is $window
     * seconds after the last *accepted* one.
     *
     * There is no atomic increment behind a generic cache, so a burst of
     * genuinely simultaneous requests can put a few over the line. That is
     * immaterial to the purpose: turning an unbounded anonymous loop into a
     * bounded one, not metering to the request.
     *
     * A non-positive $max or $window disables the check - which is how a
     * setting of 0 turns rate limiting off.
     *
     * @param string $bucket what is being limited, already composed from the
     *        action, form and caller - e.g. `save:12:203.0.113.7`
     */
    public function isRateLimited(string $bucket, int $max, int $window): bool
    {
        if ($max <= 0 || $window <= 0) {
            return false;
        }

        $cache = $this->getCache();
        $key = [self::RATE_LIMIT_PREFIX, $bucket];

        $count = (int)$cache->get($key);

        if ($count >= $max) {
            return true;
        }

        $cache->set($key, $count + 1, $window);

        return false;
    }

    /**
     * What a rendered form needs to plant its spam controls: the honeypot's
     * name (or null when off), a fresh signed timestamp (or null), whether to
     * emit the JS-token field, and the captcha widget's public data (or null).
     *
     * @return array<string, mixed>
     */
    public function getFrontEndConfig(Form $form): array
    {
        $settings = $form->getFormSettings();

        $captcha = $this->getCaptchas()->getCaptchaForForm($form);

        return [
            'honeypotField' => $settings->enableHoneypot ? $this->getSettings()->honeypotFieldName : null,
            'timeField' => self::TIME_FIELD,
            'timeToken' => $settings->minSubmitTime > 0 ? $this->createTimeToken() : null,
            'jsField' => $settings->enableJsCheck ? self::JS_FIELD : null,
            'captcha' => $captcha?->getFrontEndData(),
        ];
    }

    /**
     * Whether a value is present once trimmed - the honeypot and JS-token tests
     * both turn on "did the field come back with anything in it".
     */
    public function isFilled(?string $value): bool
    {
        return $value !== null && trim($value) !== '';
    }

    public function isTooFast(int $elapsedSeconds, int $minimumSeconds): bool
    {
        return $elapsedSeconds < $minimumSeconds;
    }

    /**
     * The first blocked keyword found in a body of text, or null. Matching is
     * case-insensitive and substring-based, so `casino` catches `Casino` and
     * `online-casino` alike.
     *
     * @param array<int, string> $keywords
     */
    public function matchesKeyword(string $text, array $keywords): ?string
    {
        if ($text === '') {
            return null;
        }

        $haystack = mb_strtolower($text);

        foreach ($keywords as $keyword) {
            $needle = mb_strtolower(trim($keyword));

            if ($needle !== '' && str_contains($haystack, $needle)) {
                return $keyword;
            }
        }

        return null;
    }

    /**
     * Whether an IP matches any blocklist pattern. A pattern is an exact
     * address or one using `*` as a wildcard for any run of characters, so
     * `203.0.113.*` shuts out a whole block.
     *
     * @param array<int, string> $patterns
     */
    public function isIpBlocked(?string $ip, array $patterns): bool
    {
        if ($ip === null || $ip === '' || $patterns === []) {
            return false;
        }

        foreach ($patterns as $pattern) {
            if ($this->ipMatches($ip, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function ipMatches(string $ip, string $pattern): bool
    {
        $pattern = trim($pattern);

        if ($pattern === '') {
            return false;
        }

        if (!str_contains($pattern, '*')) {
            return $ip === $pattern;
        }

        $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/';

        return preg_match($regex, $ip) === 1;
    }

    /**
     * Splits a newline-separated setting into its trimmed, non-empty lines.
     *
     * @return array<int, string>
     */
    public function parseList(string $raw): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];

        return array_values(array_filter(
            array_map('trim', $lines),
            static fn(string $line): bool => $line !== '',
        ));
    }

    /**
     * A signed token stamping "now". Signed with the site security key so a
     * client can't forge or replay an older timestamp to beat the timer.
     */
    public function createTimeToken(): string
    {
        return Craft::$app->getSecurity()->hashData((string)time());
    }

    /**
     * Seconds since a time token was minted, or null when the token is missing,
     * malformed, its signature doesn't check out, or it is older than a day.
     *
     * The age cap is what keeps the minimum submit time meaningful behind a
     * page cache: a visitor without JavaScript submits whatever token the cache
     * baked in, and without a cap an ever-older token would only ever read as a
     * slow, human-looking fill-in.
     */
    public function elapsedSeconds(?string $token): ?int
    {
        if ($token === null || $token === '') {
            return null;
        }

        $data = Craft::$app->getSecurity()->validateData($token);

        if ($data === false || !is_numeric($data)) {
            return null;
        }

        $elapsed = time() - (int)$data;

        return $elapsed > self::TIME_TOKEN_MAX_AGE ? null : $elapsed;
    }

    /**
     * Every value field's text, joined - what the keyword blocklist scans.
     * Encrypted fields are skipped: reading them to scan for spam would undo
     * the point of storing them ciphered.
     */
    private function collectText(Submission $submission): string
    {
        $parts = [];

        foreach ($submission->getFormFields() as $handle => $field) {
            if ($field->encrypted) {
                continue;
            }

            $string = $field->valueToString($submission->getValue($handle));

            if ($string !== '') {
                $parts[] = $string;
            }
        }

        return implode(' ', $parts);
    }
}
