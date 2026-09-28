<?php

declare(strict_types=1);

namespace bytesof\formable\elements\actions;

use bytesof\formable\elements\Submission;
use bytesof\formable\Plugin;
use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;

/**
 * Sets the submission status on the selected submissions.
 *
 * One instance is registered per configured status, so each shows up in the
 * bulk-action menu as its own single-click choice ("Mark as Approved") rather
 * than a menu that opens another menu.
 *
 * @internal
 */
final class SetSubmissionStatus extends ElementAction
{
    public ?int $statusId = null;
    public ?string $statusName = null;

    public function getTriggerLabel(): string
    {
        return Craft::t('formable', 'Mark as {status}', [
            'status' => $this->statusName ?? Craft::t('formable', 'status'),
        ]);
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        if ($this->statusId === null || Plugin::getInstance()->getStatuses()->getStatusById($this->statusId) === null) {
            $this->setMessage(Craft::t('formable', 'That status no longer exists.'));

            return false;
        }

        $submissions = Plugin::getInstance()->getSubmissions();

        /** @var Submission $submission */
        foreach ($query->all() as $submission) {
            $submissions->setStatus($submission, $this->statusId);
        }

        $this->setMessage(Craft::t('formable', 'Status updated.'));

        return true;
    }
}
