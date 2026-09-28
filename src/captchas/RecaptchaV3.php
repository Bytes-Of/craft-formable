<?php

declare(strict_types=1);

namespace bytesof\formable\captchas;

use bytesof\formable\base\Captcha;
use Craft;

/**
 * Google reCAPTCHA v3 - scores a request in the background (no challenge) and
 * returns a 0–1 confidence that it came from a human. A submission passes only
 * when the score clears the configured threshold, so this provider also weighs
 * the score rather than trusting `success` alone.
 *
 * Being invisible, it needs JavaScript to fetch a token: a no-JS submitter
 * can't produce one, and so can't satisfy a v3 captcha.
 *
 * @internal
 */
final class RecaptchaV3 extends Captcha
{
    public function __construct(
        string $siteKey,
        string $secretKey,
        private readonly float $threshold = 0.5,
    ) {
        parent::__construct($siteKey, $secretKey);
    }

    public static function handle(): string
    {
        return 'recaptchaV3';
    }

    public static function displayName(): string
    {
        return Craft::t('formable', 'reCAPTCHA v3');
    }

    public function responseParam(): string
    {
        return 'g-recaptcha-response';
    }

    public function isInvisible(): bool
    {
        return true;
    }

    public function scriptUrl(): string
    {
        // v3 loads with the site key baked in, so the page can call
        // grecaptcha.execute() for this site without a second argument.
        return 'https://www.google.com/recaptcha/api.js?render=' . rawurlencode($this->siteKey);
    }

    public function widgetClass(): string
    {
        return '';
    }

    protected function verifyUrl(): string
    {
        return 'https://www.google.com/recaptcha/api/siteverify';
    }

    /**
     * @param array<string, mixed> $data
     */
    protected function verifyResponse(array $data): bool
    {
        if (($data['success'] ?? false) !== true) {
            return false;
        }

        // A verified-but-unscored response (shouldn't happen for v3) is treated
        // as the lowest score, so it can't sneak past a threshold of 0.
        $score = isset($data['score']) && is_numeric($data['score']) ? (float)$data['score'] : 0.0;

        return $score >= $this->threshold;
    }
}
