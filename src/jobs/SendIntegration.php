<?php

declare(strict_types=1);

namespace bytesof\formable\jobs;

use bytesof\formable\Plugin;
use Craft;
use craft\queue\BaseJob;

/**
 * Forwards one submission to one integration, off the request path.
 *
 * The job carries only IDs - the integration and submission are reloaded when
 * it runs - so the queue table never holds a copy of the submitted data, and a
 * config change between queueing and running is picked up rather than baked in.
 *
 * Retries are handled here rather than through the queue's own mechanism so the
 * backoff can grow: a transient failure (the remote service down) re-queues a
 * fresh attempt on an exponential delay, up to a cap, while a permanent one
 * (a rejected payload, a gone submission) stops immediately - retrying it would
 * only fail the same way and tie up a worker.
 *
 * @internal
 */
final class SendIntegration extends BaseJob
{
    public int $integrationId;
    public int $submissionId;

    /**
     * Which attempt this is, from 1. Carried across re-queues so the backoff and
     * the give-up point both know how far we've got.
     */
    public int $attempt = 1;

    private const MAX_ATTEMPTS = 5;

    public function execute($queue): void
    {
        $integrations = Plugin::getInstance()->getIntegrations();

        // A resend can outrun a re-queued attempt still waiting on its backoff -
        // see 0077's correction. Once the target has succeeded, running again
        // would forward the same lead twice.
        if ($integrations->hasSucceeded($this->integrationId, $this->submissionId)) {
            return;
        }

        $response = $integrations->deliver($this->integrationId, $this->submissionId);

        if ($response->success || !$response->retryable || $this->attempt >= self::MAX_ATTEMPTS) {
            return;
        }

        // Exponential backoff, capped: 30s, 60s, 120s, 240s… so a service having
        // a bad few minutes gets progressively more room without a stuck one
        // hammering it or wedging the queue.
        $delay = min(3600, 30 * (2 ** ($this->attempt - 1)));

        Craft::$app->getQueue()->delay($delay)->push(new self([
            'integrationId' => $this->integrationId,
            'submissionId' => $this->submissionId,
            'attempt' => $this->attempt + 1,
        ]));
    }

    protected function defaultDescription(): string
    {
        return Craft::t('formable', 'Sending a Formable integration');
    }
}
