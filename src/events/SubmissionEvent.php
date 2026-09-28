<?php

declare(strict_types=1);

namespace bytesof\formable\events;

use bytesof\formable\elements\Submission;
use yii\base\Event;

/**
 * Fired around the submission pipeline.
 *
 * `beforeSubmit` and `beforeSaveSubmission` are cancelable - setting
 * `$event->isValid = false` aborts the pipeline, and the submitter sees the
 * form's error message rather than a success response.
 *
 * @api
 */
class SubmissionEvent extends Event
{
    public Submission $submission;

    /**
     * Whether this is the first save of the submission.
     */
    public bool $isNew = false;

    /**
     * Set to false in a `before` handler to stop the pipeline.
     */
    public bool $isValid = true;
}
