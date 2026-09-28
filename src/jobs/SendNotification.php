<?php

declare(strict_types=1);

namespace bytesof\formable\jobs;

use bytesof\formable\Plugin;
use Craft;
use craft\queue\BaseJob;
use yii\queue\RetryableJobInterface;

/**
 * Delivers one notification for one submission, off the request path.
 *
 * The job carries only IDs - the submission and notification are reloaded when
 * it runs - so the queue table never holds a copy of the submitted data. A
 * transient failure (the mail server down) throws and is retried a handful of
 * times; a permanent one (the submission or notification gone, no valid
 * recipient) finishes quietly, since retrying can't change the outcome.
 *
 * @internal
 */
final class SendNotification extends BaseJob implements RetryableJobInterface
{
    public int $submissionId;
    public int $notificationId;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $notifications = $plugin->getNotifications();

        // A resend can outrun a queue retry still waiting between attempts -
        // see 0077's correction. Once the target has succeeded, running again
        // would send the same email twice.
        if ($notifications->hasSucceeded($this->notificationId, $this->submissionId)) {
            return;
        }

        $submission = $plugin->getSubmissions()->getSubmissionById($this->submissionId);
        $notification = $notifications->getNotificationById($this->notificationId);

        // Either being gone (deleted between queueing and running) makes the
        // job a no-op - there's nothing left to send or to send about.
        if ($submission === null || $notification === null) {
            return;
        }

        $notifications->send($notification, $submission);
    }

    /**
     * Time to reserve the job for, in seconds - generous, since an SMTP
     * handshake to a slow server can take a while.
     */
    public function getTtr(): int
    {
        return 300;
    }

    /**
     * Retry a few times on a thrown (transient) failure, then give up so a
     * genuinely broken mail configuration doesn't wedge the queue.
     */
    public function canRetry($attempt, $error): bool
    {
        return $attempt < 5;
    }

    protected function defaultDescription(): string
    {
        return Craft::t('formable', 'Sending a Formable notification');
    }
}
