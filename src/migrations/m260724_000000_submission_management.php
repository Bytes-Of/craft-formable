<?php

declare(strict_types=1);

namespace bytesof\formable\migrations;

use bytesof\formable\db\Table;
use craft\db\Migration;
use craft\db\Table as CraftTable;

/**
 * Fills out the schema M7's submission management needs:
 *
 * - a `userId` on each submission, so a form submitted by a logged-in visitor
 *   can be handed back to them through `craft.formable.submissions`;
 * - a notes table, for the running commentary staff leave on a submission;
 * - a history table, for the field-level edit trail (Pro).
 *
 * Additive and individually guarded - a fresh install runs the (updated)
 * Install migration first, so everything here may already exist.
 */
final class m260724_000000_submission_management extends Migration
{
    public function safeUp(): bool
    {
        $submissions = $this->db->getTableSchema(Table::SUBMISSIONS);

        if ($submissions !== null && !isset($submissions->columns['userId'])) {
            $this->addColumn(Table::SUBMISSIONS, 'userId', (string)$this->integer()->null()->after('statusId'));
            $this->createIndex(null, Table::SUBMISSIONS, ['userId'], false);
            $this->addForeignKey(null, Table::SUBMISSIONS, ['userId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);
        }

        if ($this->db->getTableSchema(Table::SUBMISSION_NOTES) === null) {
            $this->createTable(Table::SUBMISSION_NOTES, [
                'id' => $this->primaryKey(),
                'submissionId' => $this->integer()->notNull(),
                // The author, SET NULL on delete: a note is an audit record that
                // has to outlive the account of whoever left it.
                'userId' => $this->integer()->null(),
                'note' => $this->text()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, Table::SUBMISSION_NOTES, ['submissionId'], false);
            $this->addForeignKey(null, Table::SUBMISSION_NOTES, ['submissionId'], Table::SUBMISSIONS, ['id'], 'CASCADE', null);
            $this->addForeignKey(null, Table::SUBMISSION_NOTES, ['userId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);
        }

        if ($this->db->getTableSchema(Table::SUBMISSION_HISTORY) === null) {
            $this->createTable(Table::SUBMISSION_HISTORY, [
                'id' => $this->primaryKey(),
                'submissionId' => $this->integer()->notNull(),
                'userId' => $this->integer()->null(),
                // Each row is one save's worth of before/after values, keyed by
                // field handle - enough to reconstruct what changed and when.
                'changes' => $this->json(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, Table::SUBMISSION_HISTORY, ['submissionId'], false);
            $this->addForeignKey(null, Table::SUBMISSION_HISTORY, ['submissionId'], Table::SUBMISSIONS, ['id'], 'CASCADE', null);
            $this->addForeignKey(null, Table::SUBMISSION_HISTORY, ['userId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::SUBMISSION_HISTORY);
        $this->dropTableIfExists(Table::SUBMISSION_NOTES);

        $submissions = $this->db->getTableSchema(Table::SUBMISSIONS);

        if ($submissions !== null && isset($submissions->columns['userId'])) {
            $this->dropColumn(Table::SUBMISSIONS, 'userId');
        }

        return true;
    }
}
