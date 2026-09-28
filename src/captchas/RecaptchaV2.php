<?php

declare(strict_types=1);

namespace bytesof\formable\captchas;

use bytesof\formable\base\Captcha;
use Craft;

/**
 * Google reCAPTCHA v2 - the “I'm not a robot” checkbox (or invisible badge),
 * verified server-side against Google's siteverify endpoint.
 *
 * @internal
 */
final class RecaptchaV2 extends Captcha
{
    public static function handle(): string
    {
        return 'recaptchaV2';
    }

    public static function displayName(): string
    {
        return Craft::t('formable', 'reCAPTCHA v2');
    }

    public function responseParam(): string
    {
        return 'g-recaptcha-response';
    }

    public function scriptUrl(): string
    {
        return 'https://www.google.com/recaptcha/api.js';
    }

    public function widgetClass(): string
    {
        return 'g-recaptcha';
    }

    protected function verifyUrl(): string
    {
        return 'https://www.google.com/recaptcha/api/siteverify';
    }
}
