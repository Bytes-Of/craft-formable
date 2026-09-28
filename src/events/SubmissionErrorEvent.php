<?php

declare(strict_types=1);

namespace bytesof\formable\events;

use bytesof\formable\elements\Submission;
use yii\base\Event;

/**
 * Fired when a submission is rejected, whether by field validation, by a
 * cancelled `before` event, or by a failed save.
 *
 * Gives spam handling and logging a single hook to listen on, rather than
 * having to infer failure from the absence of `afterSubmit`.
 *
 * @api
 */
class SubmissionErrorEvent extends Event
{
    public const REASON_VALIDATION = 'validation';
    public const REASON_CANCELED = 'canceled';
    public const REASON_SAVE_FAILED = 'saveFailed';
    public const REASON_SPAM = 'spam';

    public Submission $submission;

    /**
     * One of the `REASON_*` constants.
     */
    public string $reason = self::REASON_VALIDATION;
}
