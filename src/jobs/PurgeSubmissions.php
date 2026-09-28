<?php

declare(strict_types=1);

namespace bytesof\formable\jobs;

use bytesof\formable\Plugin;
use bytesof\formable\services\Submissions;
use Craft;
use craft\queue\BaseJob;

/**
 * Deletes expired in-progress submissions and submissions past their form's
 * retention window, a bounded amount per run.
 *
 * Garbage collection queues this rather than purging inline: GC fires on a
 * random web request, and the first run after retention is switched on for a
 * form with a year of history is hundreds of thousands of element deletes
 * ([[0112-submission-indexes-follow-the-measured-query-shapes]]). A run that
 * hits either limit pushes a fresh job for the rest, so each reservation stays
 * short and a backlog of any size drains without a worker holding it for hours.
 *
 * @internal
 */
final class PurgeSubmissions extends BaseJob
{
    public function execute($queue): void
    {
        $submissions = Plugin::getInstance()->getSubmissions();

        $incomplete = $submissions->purgeExpiredIncomplete();
        $this->setProgress($queue, 0.5);
        $retention = $submissions->purgeForRetention();

        if ($incomplete >= Submissions::PURGE_LIMIT || $retention >= Submissions::PURGE_LIMIT) {
            Craft::$app->getQueue()->push(new self());
        }
    }

    protected function defaultDescription(): string
    {
        return Craft::t('formable', 'Deleting expired submissions');
    }
}
