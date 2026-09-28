<?php

declare(strict_types=1);

namespace bytesof\formable\jobs;

use bytesof\formable\elements\Submission;
use Craft;
use craft\queue\BaseJob;

/**
 * Cascades a form's own soft-delete (trash) or restore to its submissions,
 * off the request path.
 *
 * Only reached for a form with more submissions than
 * {@see \bytesof\formable\elements\Form}'s sync cascade limit - a smaller form
 * cascades inline in `beforeDelete()`/`afterRestore()` so the CP action
 * finishes in the same request. Never used for a hard delete: that removes
 * the form's `formable_forms` row immediately, which cascades away its
 * `formable_submissions` rows before a queued job could ever reach them by
 * id, so `Form::beforeDelete()` always cascades a hard delete inline instead
 * of queuing it. The job carries only ids, reloading each submission when it
 * runs, matching {@see SendNotification} and {@see SendIntegration}.
 *
 * @internal
 */
final class CascadeSubmissions extends BaseJob
{
    /** @var int[] */
    public array $submissionIds = [];

    public bool $restore = false;

    public function execute($queue): void
    {
        $elements = Craft::$app->getElements();
        $total = count($this->submissionIds);

        foreach ($this->submissionIds as $index => $id) {
            $submission = Submission::find()
                ->id($id)
                ->status(null)
                ->trashed($this->restore)
                ->one();

            if ($submission === null) {
                continue;
            }

            if ($this->restore) {
                $elements->restoreElement($submission);
            } else {
                $submission->deletedWithOwner = true;
                $elements->deleteElement($submission);
            }

            $this->setProgress($queue, ($index + 1) / $total);
        }
    }

    /**
     * A generous reservation - a large form's cascade walks its submissions
     * one at a time, each its own save or delete.
     */
    public function getTtr(): int
    {
        return 900;
    }

    protected function defaultDescription(): string
    {
        return $this->restore
            ? Craft::t('formable', 'Restoring a form’s submissions')
            : Craft::t('formable', 'Deleting a form’s submissions');
    }
}
