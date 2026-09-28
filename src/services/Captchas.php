<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\base\Captcha;
use bytesof\formable\captchas\Hcaptcha;
use bytesof\formable\captchas\RecaptchaV2;
use bytesof\formable\captchas\RecaptchaV3;
use bytesof\formable\captchas\Turnstile;
use bytesof\formable\elements\Form;
use bytesof\formable\models\Settings;
use bytesof\formable\Plugin;
use craft\helpers\App;
use yii\base\Component;

/**
 * Resolves configured captcha providers from the plugin settings.
 *
 * The registry is built once from the credentials the admin entered, so
 * callers ask for "the captcha this form uses" without touching keys or env
 * parsing themselves. Captchas are a Pro feature - {@see getCaptchaForForm()}
 * returns nothing in Lite regardless of what a form has selected.
 *
 * @internal
 */
final class Captchas extends Component
{
    private ?Settings $_settings = null;

    /**
     * Overrides the settings the registry reads, so provider resolution can be
     * unit tested without a booted plugin.
     */
    public function setSettings(Settings $settings): void
    {
        $this->_settings = $settings;
    }

    public function getSettings(): Settings
    {
        return $this->_settings ??= Plugin::getInstance()->getSettings();
    }

    /**
     * Every provider, configured or not, keyed by handle. Secret keys are
     * resolved through env parsing here so no other layer has to.
     *
     * @return array<string, Captcha>
     */
    public function getAllCaptchas(): array
    {
        $settings = $this->getSettings();

        return [
            RecaptchaV2::handle() => new RecaptchaV2(
                trim($settings->recaptchaV2SiteKey),
                (string)App::parseEnv($settings->recaptchaV2SecretKey),
            ),
            RecaptchaV3::handle() => new RecaptchaV3(
                trim($settings->recaptchaV3SiteKey),
                (string)App::parseEnv($settings->recaptchaV3SecretKey),
                $settings->recaptchaV3Threshold,
            ),
            Hcaptcha::handle() => new Hcaptcha(
                trim($settings->hcaptchaSiteKey),
                (string)App::parseEnv($settings->hcaptchaSecretKey),
            ),
            Turnstile::handle() => new Turnstile(
                trim($settings->turnstileSiteKey),
                (string)App::parseEnv($settings->turnstileSecretKey),
            ),
        ];
    }

    /**
     * A provider by handle, or null for an unknown one.
     */
    public function getCaptcha(string $handle): ?Captcha
    {
        return $this->getAllCaptchas()[$handle] ?? null;
    }

    /**
     * The captcha a form actually enforces: the one it selected, but only when
     * captchas are available (Pro) and the provider has its keys. Anything else
     * - Lite, no selection, an unconfigured or unknown provider - yields null,
     * and the form renders and submits without a captcha.
     */
    public function getCaptchaForForm(Form $form): ?Captcha
    {
        if (!Plugin::getInstance()->isPro()) {
            return null;
        }

        $handle = $form->getFormSettings()->captcha;

        if ($handle === '') {
            return null;
        }

        $captcha = $this->getCaptcha($handle);

        return $captcha !== null && $captcha->isConfigured() ? $captcha : null;
    }
}
