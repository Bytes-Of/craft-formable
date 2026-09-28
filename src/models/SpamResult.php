<?php

declare(strict_types=1);

namespace bytesof\formable\models;

/**
 * The verdict of the spam checks on one submission: whether it looks like spam
 * and, if so, which check caught it.
 *
 * The reason is stored on the submission (see {@see \bytesof\formable\elements\Submission::$spamReason})
 * as a stable code, not a translated string, so the CP can label it in the
 * viewer's language whenever they look.
 *
 * @internal
 */
final class SpamResult
{
    public function __construct(
        public readonly bool $isSpam,
        public readonly ?SpamReason $reason = null,
    ) {
    }

    public static function clean(): self
    {
        return new self(false);
    }

    public static function spam(SpamReason $reason): self
    {
        return new self(true, $reason);
    }
}
