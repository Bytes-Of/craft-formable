<?php

declare(strict_types=1);

namespace bytesof\formable\migrations;

use bytesof\formable\db\Table;
use craft\db\Migration;

/**
 * Fills out the notifications schema for M6: a From name/address and an
 * attach-files flag on each notification, plus the send log that records every
 * delivery attempt.
 *
 * The `formable_notifications` table already existed (created empty-of-purpose
 * by the Install migration), so this only adds the columns M6 introduced and
 * the new log table.
 */
final class m260723_000000_notifications extends Migration
{
    public function safeUp(): bool
    {
        $notifications = $this->db->getTableSchema(Table::NOTIFICATIONS);

        // Guarded individually: a fresh install runs the (updated) Install
        // migration and then this one, so the columns may already be there.
        if ($notifications !== null) {
            if (!isset($notifications->columns['fromName'])) {
                $this->addColumn(Table::NOTIFICATIONS, 'fromName', (string)$this->string()->null()->after('replyTo'));
            }

            if (!isset($notifications->columns['fromEmail'])) {
                $this->addColumn(Table::NOTIFICATIONS, 'fromEmail', (string)$this->string()->null()->after('fromName'));
            }

            if (!isset($notifications->columns['attachFiles'])) {
                $this->addColumn(
                    Table::NOTIFICATIONS,
                    'attachFiles',
                    (string)$this->boolean()->notNull()->defaultValue(false)->after('body'),
                );
            }
        }

        if ($this->db->getTableSchema(Table::NOTIFICATIONS_LOG) === null) {
            $this->createTable(Table::NOTIFICATIONS_LOG, [
                'id' => $this->primaryKey(),
                'notificationId' => $this->integer()->null(),
                'submissionId' => $this->integer()->null(),
                'formId' => $this->integer()->null(),
                'success' => $this->boolean()->notNull()->defaultValue(false),
                'recipients' => $this->text()->null(),
                'subject' => $this->text()->null(),
                'error' => $this->text()->null(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, Table::NOTIFICATIONS_LOG, ['notificationId'], false);
            $this->createIndex(null, Table::NOTIFICATIONS_LOG, ['submissionId'], false);
            $this->createIndex(null, Table::NOTIFICATIONS_LOG, ['formId'], false);

            $this->addForeignKey(null, Table::NOTIFICATIONS_LOG, ['notificationId'], Table::NOTIFICATIONS, ['id'], 'SET NULL', null);
            $this->addForeignKey(null, Table::NOTIFICATIONS_LOG, ['submissionId'], Table::SUBMISSIONS, ['id'], 'SET NULL', null);
            $this->addForeignKey(null, Table::NOTIFICATIONS_LOG, ['formId'], Table::FORMS, ['id'], 'SET NULL', null);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::NOTIFICATIONS_LOG);

        $notifications = $this->db->getTableSchema(Table::NOTIFICATIONS);

        if ($notifications !== null) {
            foreach (['fromName', 'fromEmail', 'attachFiles'] as $column) {
                if (isset($notifications->columns[$column])) {
                    $this->dropColumn(Table::NOTIFICATIONS, $column);
                }
            }
        }

        return true;
    }
}
