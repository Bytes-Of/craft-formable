<?php

declare(strict_types=1);

namespace bytesof\formable\captchas;

use bytesof\formable\base\Captcha;
use Craft;

/**
 * hCaptcha - a privacy-focused challenge widget, verified against hCaptcha's
 * siteverify endpoint. The wire protocol mirrors reCAPTCHA's, so only the URLs
 * and field name differ.
 *
 * @internal
 */
final class Hcaptcha extends Captcha
{
    public static function handle(): string
    {
        return 'hcaptcha';
    }

    public static function displayName(): string
    {
        return Craft::t('formable', 'hCaptcha');
    }

    public function responseParam(): string
    {
        return 'h-captcha-response';
    }

    public function scriptUrl(): string
    {
        return 'https://js.hcaptcha.com/1/api.js';
    }

    public function widgetClass(): string
    {
        return 'h-captcha';
    }

    protected function verifyUrl(): string
    {
        return 'https://api.hcaptcha.com/siteverify';
    }
}
