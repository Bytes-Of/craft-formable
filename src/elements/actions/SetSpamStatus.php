<?php

declare(strict_types=1);

namespace bytesof\formable\elements\actions;

use bytesof\formable\elements\Submission;
use bytesof\formable\Plugin;
use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;

/**
 * Marks the selected submissions as spam, or clears the flag.
 *
 * Two instances are registered - one to flag, one to clear - so the spam queue
 * offers "Not spam" to rescue a false positive and the ordinary index offers
 * "Mark as spam" to bin one the checks missed, each a single click.
 *
 * @internal
 */
final class SetSpamStatus extends ElementAction
{
    /**
     * Whether this instance flags submissions as spam (true) or clears the flag
     * (false).
     */
    public bool $spam = true;

    public function getTriggerLabel(): string
    {
        return $this->spam
            ? Craft::t('formable', 'Mark as spam')
            : Craft::t('formable', 'Not spam');
    }

    public function performAction(ElementQueryInterface $query): bool
    {
        $submissions = Plugin::getInstance()->getSubmissions();

        /** @var Submission $submission */
        foreach ($query->all() as $submission) {
            $submissions->setSpam($submission, $this->spam);
        }

        $this->setMessage($this->spam
            ? Craft::t('formable', 'Marked as spam.')
            : Craft::t('formable', 'Marked as not spam.'));

        return true;
    }
}
