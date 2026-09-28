<?php

declare(strict_types=1);

namespace bytesof\formable\migrations;

use bytesof\formable\db\Table;
use bytesof\formable\elements\Submission;
use bytesof\formable\Plugin;
use Craft;
use craft\db\Migration;
use craft\helpers\Db;
use Throwable;

/**
 * Backfills the relations index for submissions stored before it was written to.
 *
 * `formable_relations` was created by the install migration and never populated:
 * every entry, category and asset a submission pointed at lived only as a bare
 * id inside the `formData` JSON, where no query could reach it. Submissions are
 * indexed on save now - see
 * internal/decisions/0022-submission-relations-are-indexed-on-save.md - which
 * leaves everything already stored invisible to the index until it next happens
 * to be saved. This walks them once.
 *
 * Spam, incomplete and trashed submissions are included: they hold uploads and
 * selections like any other, and someone asking what still references an entry
 * wants the true answer rather than the tidy one.
 */
final class m260809_200000_index_submission_relations extends Migration
{
    public function safeUp(): bool
    {
        $submissions = Plugin::getInstance()->getSubmissions();

        $query = Submission::find()
            ->isIncomplete(null)
            ->isSpam(null)
            ->status(null)
            ->trashed(null);

        // Batched: an install with a large submissions table would otherwise
        // hold every element in memory at once for a one-off index write.
        foreach (Db::each($query) as $submission) {
            /** @var Submission $submission */
            try {
                $submissions->saveRelations($submission);
            } catch (Throwable $e) {
                // One unreadable submission - a form deleted out from under it,
                // a value that no longer normalizes - must not strand the
                // update. It stays absent from the index until it is next
                // saved, which is exactly where it was before this migration.
                Craft::warning(
                    sprintf(
                        'Could not index relations for Formable submission %d: %s',
                        (int)$submission->id,
                        $e->getMessage(),
                    ),
                    __METHOD__,
                );
            }
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->delete(Table::RELATIONS);

        return true;
    }
}
