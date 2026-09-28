<?php

declare(strict_types=1);

namespace bytesof\formable\migrations;

use bytesof\formable\db\Table;
use craft\db\Migration;

/**
 * Replaces the single-column form and spam indexes on submissions with the two
 * composites the index pages, widgets and digests actually filter and sort by.
 * See internal/decisions/0112-submission-indexes-follow-the-measured-query-shapes.md.
 *
 * The composites are created before the old indexes go: on MySQL the `formId`
 * foreign key needs an index that starts with `formId` at every moment.
 */
final class m260927_000000_submission_indexes extends Migration
{
    public function safeUp(): bool
    {
        $this->createIndexIfMissing(Table::SUBMISSIONS, Install::SUBMISSION_FORM_INDEX);
        $this->createIndexIfMissing(Table::SUBMISSIONS, Install::SUBMISSION_SOURCE_INDEX);
        $this->dropIndexIfExists(Table::SUBMISSIONS, ['formId']);
        $this->dropIndexIfExists(Table::SUBMISSIONS, ['isSpam']);

        return true;
    }

    public function safeDown(): bool
    {
        $this->createIndexIfMissing(Table::SUBMISSIONS, ['formId']);
        $this->createIndexIfMissing(Table::SUBMISSIONS, ['isSpam']);
        $this->dropIndexIfExists(Table::SUBMISSIONS, Install::SUBMISSION_FORM_INDEX);
        $this->dropIndexIfExists(Table::SUBMISSIONS, Install::SUBMISSION_SOURCE_INDEX);

        return true;
    }
}
