<?php

declare(strict_types=1);

namespace bytesof\formable\migrations;

use bytesof\formable\db\Table;
use craft\db\Migration;

/**
 * Adds the columns save-and-resume needs to the submissions table.
 *
 * An incomplete submission is a real row from the moment the submitter leaves
 * the first page - that's what makes a multi-page form survive a closed tab,
 * and what a resume link points at. It's kept out of the CP index by the
 * `isIncomplete` flag that already existed.
 */
final class m260722_000000_incomplete_submissions extends Migration
{
    public function safeUp(): bool
    {
        $table = $this->db->getTableSchema(Table::SUBMISSIONS);

        if ($table === null) {
            return true;
        }

        // Guarded individually: a fresh install runs the (updated) Install
        // migration and then this one, so the columns may already be there.
        // The builders are rendered to their SQL type here - addColumn casts
        // them the same way, and passing the string keeps the static analyzer
        // (whose yii stub types the argument as string) happy.
        if (!isset($table->columns['resumeToken'])) {
            $this->addColumn(Table::SUBMISSIONS, 'resumeToken', (string)$this->char(32)->null());
            $this->createIndex(null, Table::SUBMISSIONS, ['resumeToken'], true);
        }

        if (!isset($table->columns['pageIndex'])) {
            $this->addColumn(
                Table::SUBMISSIONS,
                'pageIndex',
                (string)$this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            );
        }

        if (!isset($table->columns['dateExpired'])) {
            $this->addColumn(Table::SUBMISSIONS, 'dateExpired', (string)$this->dateTime()->null());
            $this->createIndex(null, Table::SUBMISSIONS, ['dateExpired'], false);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $table = $this->db->getTableSchema(Table::SUBMISSIONS);

        if ($table === null) {
            return true;
        }

        foreach (['resumeToken', 'pageIndex', 'dateExpired'] as $column) {
            if (isset($table->columns[$column])) {
                $this->dropColumn(Table::SUBMISSIONS, $column);
            }
        }

        return true;
    }
}
