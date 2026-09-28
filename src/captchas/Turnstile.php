<?php

declare(strict_types=1);

namespace bytesof\formable\captchas;

use bytesof\formable\base\Captcha;
use Craft;

/**
 * Cloudflare Turnstile - a non-interactive challenge that verifies against
 * Cloudflare's siteverify endpoint, again over the same success-flag protocol
 * as reCAPTCHA and hCaptcha.
 *
 * @internal
 */
final class Turnstile extends Captcha
{
    public static function handle(): string
    {
        return 'turnstile';
    }

    public static function displayName(): string
    {
        return Craft::t('formable', 'Cloudflare Turnstile');
    }

    public function responseParam(): string
    {
        return 'cf-turnstile-response';
    }

    public function scriptUrl(): string
    {
        return 'https://challenges.cloudflare.com/turnstile/v0/api.js';
    }

    public function widgetClass(): string
    {
        return 'cf-turnstile';
    }

    protected function verifyUrl(): string
    {
        return 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    }
}
