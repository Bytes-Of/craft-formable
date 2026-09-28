<?php

declare(strict_types=1);

namespace bytesof\formable\models;

use Craft;

/**
 * Which spam check flagged a submission.
 *
 * The case value is the stable code stored on
 * {@see \bytesof\formable\elements\Submission::$spamReason}; {@see label()}
 * translates it at the point it's shown, so the CP labels a stored verdict in
 * the current viewer's language rather than the one it was flagged in.
 *
 * @internal
 */
enum SpamReason: string
{
    case Honeypot = 'honeypot';
    case Js = 'jsCheck';
    case TooFast = 'tooFast';
    case Keyword = 'keyword';
    case Ip = 'ipBlocked';
    case Captcha = 'captcha';

    /**
     * A human-readable label for a stored reason code, falling back to a generic
     * "Spam" for a null code or one this version no longer recognises (an older
     * code, or a check that has since been removed).
     */
    public static function labelFor(?string $code): string
    {
        $reason = $code !== null ? self::tryFrom($code) : null;

        return $reason?->label() ?? Craft::t('formable', 'Spam');
    }

    public function label(): string
    {
        return match ($this) {
            self::Honeypot => Craft::t('formable', 'Honeypot filled'),
            self::Js => Craft::t('formable', 'Failed JavaScript check'),
            self::TooFast => Craft::t('formable', 'Submitted too quickly'),
            self::Keyword => Craft::t('formable', 'Blocked keyword'),
            self::Ip => Craft::t('formable', 'Blocked IP address'),
            self::Captcha => Craft::t('formable', 'Failed captcha'),
        };
    }
}
