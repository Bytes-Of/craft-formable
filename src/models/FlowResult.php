<?php

declare(strict_types=1);

namespace bytesof\formable\models;

use bytesof\formable\elements\Submission;

/**
 * What came of a move through a form, with no opinion on how to report it.
 *
 * The flow answers in outcomes - advanced, completed, rejected, saved - and
 * leaves each caller to say so in its own language: a redirect and a flash for
 * a plain form post, JSON for the AJAX path, a payload for GraphQL. The
 * submission always rides along, carrying its own errors, so a caller re-renders
 * the submitter's own values rather than an empty form.
 *
 * @internal
 */
final class FlowResult
{
    /** Moved to another page (or to the review step). */
    public const ADVANCED = 'advanced';

    /** The submission went through the full pipeline and is done. */
    public const COMPLETED = 'completed';

    /** Validation - or the spam gate - turned the move away. */
    public const INVALID = 'invalid';

    /** Parked for later; the resume link is on its way. */
    public const SAVED = 'saved';

    /** Save was attempted and could not be honoured (no email, write failed). */
    public const SAVE_FAILED = 'save_failed';

    /** This form cannot be saved for later at all - not enabled, or not Pro. */
    public const SAVE_UNAVAILABLE = 'save_unavailable';

    private function __construct(
        public readonly string $status,
        public readonly Submission $submission,
        public readonly int $page,
        public readonly bool $isReview = false,
        public readonly ?string $message = null,
    ) {
    }

    public static function advanced(Submission $submission, int $page, bool $isReview): self
    {
        return new self(self::ADVANCED, $submission, $page, $isReview);
    }

    public static function completed(Submission $submission): self
    {
        return new self(self::COMPLETED, $submission, 0);
    }

    public static function invalid(Submission $submission, int $page): self
    {
        return new self(self::INVALID, $submission, $page);
    }

    public static function saved(Submission $submission, int $page, string $message): self
    {
        return new self(self::SAVED, $submission, $page, false, $message);
    }

    public static function saveFailed(Submission $submission, int $page, string $message): self
    {
        return new self(self::SAVE_FAILED, $submission, $page, false, $message);
    }

    public static function saveUnavailable(Submission $submission, int $page): self
    {
        return new self(self::SAVE_UNAVAILABLE, $submission, $page);
    }

    public function isSuccess(): bool
    {
        return in_array($this->status, [self::ADVANCED, self::COMPLETED, self::SAVED], true);
    }
}
