<?php

declare(strict_types=1);

namespace bytesof\formable\migrations;

use bytesof\formable\db\Table;
use bytesof\formable\models\Status;
use bytesof\formable\Plugin;
use bytesof\formable\services\Statuses;
use Craft;
use craft\db\Migration;
use craft\db\Table as CraftTable;
use craft\helpers\StringHelper;

/**
 * Install migration: creates all Formable tables and seeds the default
 * submission statuses.
 */
final class Install extends Migration
{
    /**
     * A form's submissions, newest first: its CP source, the recent-submissions
     * widget, the digest window and the retention cutoff. The spam and
     * in-progress flags sit between the form and the date because every one of
     * those reads fixes them, which is what lets the date be read in index
     * order ([[0112-submission-indexes-follow-the-measured-query-shapes]]).
     */
    public const SUBMISSION_FORM_INDEX = ['formId', 'isSpam', 'isIncomplete', 'dateCreated'];

    /**
     * The same read across every form - the "All submissions" and "Spam"
     * sources.
     */
    public const SUBMISSION_SOURCE_INDEX = ['isSpam', 'isIncomplete', 'dateCreated'];

    public function safeUp(): bool
    {
        $this->createTables();
        $this->createIndexes();
        $this->addForeignKeys();
        $this->insertDefaultData();

        return true;
    }

    public function safeDown(): bool
    {
        // Drop in FK-safe order (children first)
        $this->dropTableIfExists(Table::UPLOADS);
        $this->dropTableIfExists(Table::RELATIONS);
        $this->dropTableIfExists(Table::INTEGRATIONS_LOG);
        $this->dropTableIfExists(Table::INTEGRATIONS);
        $this->dropTableIfExists(Table::NOTIFICATIONS_LOG);
        $this->dropTableIfExists(Table::NOTIFICATIONS);
        $this->dropTableIfExists(Table::SUBMISSION_HISTORY);
        $this->dropTableIfExists(Table::SUBMISSION_NOTES);
        $this->dropTableIfExists(Table::SUBMISSIONS);
        $this->dropTableIfExists(Table::FORMS);
        $this->dropTableIfExists(Table::STATUSES);

        return true;
    }

    private function createTables(): void
    {
        $this->archiveTableIfExists(Table::STATUSES);
        $this->createTable(Table::STATUSES, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'color' => $this->string(20)->notNull()->defaultValue('blue'),
            'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'isDefault' => $this->boolean()->notNull()->defaultValue(false),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'dateDeleted' => $this->dateTime()->null(),
            'uid' => $this->uid(),
        ]);

        $this->archiveTableIfExists(Table::FORMS);
        $this->createTable(Table::FORMS, [
            'id' => $this->integer()->notNull(),
            'handle' => $this->string()->notNull(),
            'pages' => $this->json(),
            'settings' => $this->json(),
            'translations' => $this->json(),
            'defaultStatusId' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->archiveTableIfExists(Table::SUBMISSIONS);
        $this->createTable(Table::SUBMISSIONS, [
            'id' => $this->integer()->notNull(),
            'formId' => $this->integer()->notNull(),
            'statusId' => $this->integer()->null(),
            // The logged-in visitor who submitted it, if any - what
            // `craft.formable.submissions` scopes a member's own submissions to.
            'userId' => $this->integer()->null(),
            // Named formData (not "content") to avoid clashing with Craft's
            // elements_sites.content selection in element queries.
            'formData' => $this->json(),
            // The site the form was submitted from. Not `siteId`: an element
            // already has one of those (the elements_sites row it was loaded
            // for), and a column of that name would be selected straight over
            // it. Nullable because a submission can be made where there is no
            // meaningful current site - a console replay, a queued import - and
            // because rows written before this column existed have no answer.
            'submittedSiteId' => $this->integer()->null(),
            'ipAddress' => $this->string(45)->null(),
            'userAgent' => $this->text()->null(),
            'isIncomplete' => $this->boolean()->notNull()->defaultValue(false),
            'isSpam' => $this->boolean()->notNull()->defaultValue(false),
            // Which spam check flagged it (a stable code, not a message), so the
            // CP can tell a reviewer why a submission landed in the spam queue.
            'spamReason' => $this->string()->null(),
            // Save-and-resume: an incomplete submission is a real row from the
            // moment the submitter leaves page one, addressed by an unguessable
            // token and reaped once `dateExpired` passes.
            'resumeToken' => $this->char(32)->null(),
            'pageIndex' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'dateExpired' => $this->dateTime()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
            'PRIMARY KEY([[id]])',
        ]);

        $this->archiveTableIfExists(Table::SUBMISSION_NOTES);
        $this->createTable(Table::SUBMISSION_NOTES, [
            'id' => $this->primaryKey(),
            'submissionId' => $this->integer()->notNull(),
            'userId' => $this->integer()->null(),
            'note' => $this->text()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->archiveTableIfExists(Table::SUBMISSION_HISTORY);
        $this->createTable(Table::SUBMISSION_HISTORY, [
            'id' => $this->primaryKey(),
            'submissionId' => $this->integer()->notNull(),
            'userId' => $this->integer()->null(),
            'changes' => $this->json(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->archiveTableIfExists(Table::NOTIFICATIONS);
        $this->createTable(Table::NOTIFICATIONS, [
            'id' => $this->primaryKey(),
            'formId' => $this->integer()->notNull(),
            'name' => $this->string()->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'recipients' => $this->text()->null(),
            'cc' => $this->text()->null(),
            'bcc' => $this->text()->null(),
            'replyTo' => $this->string()->null(),
            'fromName' => $this->string()->null(),
            'fromEmail' => $this->string()->null(),
            'subject' => $this->text()->null(),
            'body' => $this->text()->null(),
            'attachFiles' => $this->boolean()->notNull()->defaultValue(false),
            'conditions' => $this->json(),
            'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->archiveTableIfExists(Table::NOTIFICATIONS_LOG);
        $this->createTable(Table::NOTIFICATIONS_LOG, [
            'id' => $this->primaryKey(),
            // All three are nullable and SET NULL on delete: the log is an
            // audit trail that has to outlive the notification, submission or
            // form it records - a purged submission mustn't erase the evidence
            // an email was sent, and a form that never stored the submission
            // has no id to point at.
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

        $this->archiveTableIfExists(Table::INTEGRATIONS);
        $this->createTable(Table::INTEGRATIONS, [
            'id' => $this->primaryKey(),
            'name' => $this->string()->notNull(),
            'handle' => $this->string()->notNull(),
            'type' => $this->string()->notNull(),
            'enabled' => $this->boolean()->notNull()->defaultValue(true),
            'settings' => $this->json(),
            'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->archiveTableIfExists(Table::INTEGRATIONS_LOG);
        $this->createTable(Table::INTEGRATIONS_LOG, [
            'id' => $this->primaryKey(),
            'integrationId' => $this->integer()->notNull(),
            'submissionId' => $this->integer()->null(),
            'success' => $this->boolean()->notNull()->defaultValue(false),
            'message' => $this->text()->null(),
            'payload' => $this->json(),
            'response' => $this->json(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->archiveTableIfExists(Table::RELATIONS);
        $this->createTable(Table::RELATIONS, [
            'id' => $this->primaryKey(),
            'submissionId' => $this->integer()->notNull(),
            'elementId' => $this->integer()->notNull(),
            'fieldHandle' => $this->string()->notNull(),
            'sortOrder' => $this->smallInteger()->unsigned()->notNull()->defaultValue(0),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createUploadsTable();
    }

    /**
     * Shared with the migration that added it, so the two can't drift.
     */
    public function createUploadsTable(): void
    {
        $this->archiveTableIfExists(Table::UPLOADS);
        $this->createTable(Table::UPLOADS, [
            'id' => $this->primaryKey(),
            'assetId' => $this->integer()->notNull(),
            // No foreign key, deliberately: Craft's garbage collection
            // hard-deletes a trashed submission with a raw DELETE, and a
            // cascade would take this row with it - the only record of which
            // files the submission owned. The row is left pointing at a
            // submission that no longer exists, which is what marks it for
            // cleanup. Null until a stored submission claims the upload.
            'submissionId' => $this->integer()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::UPLOADS, ['assetId'], true);
        $this->createIndex(null, Table::UPLOADS, ['submissionId'], false);
        $this->addForeignKey(null, Table::UPLOADS, ['assetId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
    }

    private function createIndexes(): void
    {
        // Handles are validated as unique among non-trashed elements, so the
        // index stays non-unique to allow soft-deleted duplicates.
        $this->createIndex(null, Table::FORMS, ['handle'], false);
        $this->createIndex(null, Table::STATUSES, ['handle'], false);
        $this->createIndex(null, Table::STATUSES, ['isDefault'], false);
        $this->createIndex(null, Table::SUBMISSIONS, self::SUBMISSION_FORM_INDEX, false);
        $this->createIndex(null, Table::SUBMISSIONS, self::SUBMISSION_SOURCE_INDEX, false);
        $this->createIndex(null, Table::SUBMISSIONS, ['statusId'], false);
        $this->createIndex(null, Table::SUBMISSIONS, ['userId'], false);
        $this->createIndex(null, Table::SUBMISSIONS, ['submittedSiteId'], false);
        $this->createIndex(null, Table::SUBMISSIONS, ['isIncomplete'], false);
        // Unique: a resume token is the only credential a resume link carries.
        $this->createIndex(null, Table::SUBMISSIONS, ['resumeToken'], true);
        $this->createIndex(null, Table::SUBMISSIONS, ['dateExpired'], false);
        $this->createIndex(null, Table::SUBMISSION_NOTES, ['submissionId'], false);
        $this->createIndex(null, Table::SUBMISSION_HISTORY, ['submissionId'], false);
        $this->createIndex(null, Table::NOTIFICATIONS, ['formId'], false);
        $this->createIndex(null, Table::NOTIFICATIONS_LOG, ['notificationId'], false);
        $this->createIndex(null, Table::NOTIFICATIONS_LOG, ['submissionId'], false);
        $this->createIndex(null, Table::NOTIFICATIONS_LOG, ['formId'], false);
        $this->createIndex(null, Table::INTEGRATIONS, ['handle'], true);
        $this->createIndex(null, Table::INTEGRATIONS_LOG, ['integrationId'], false);
        $this->createIndex(null, Table::INTEGRATIONS_LOG, ['submissionId'], false);
        $this->createIndex(null, Table::RELATIONS, ['submissionId'], false);
        $this->createIndex(null, Table::RELATIONS, ['elementId'], false);
        $this->createIndex(null, Table::RELATIONS, ['submissionId', 'fieldHandle'], false);
    }

    private function addForeignKeys(): void
    {
        $this->addForeignKey(null, Table::FORMS, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::FORMS, ['defaultStatusId'], Table::STATUSES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::SUBMISSIONS, ['id'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::SUBMISSIONS, ['formId'], Table::FORMS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::SUBMISSIONS, ['statusId'], Table::STATUSES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::SUBMISSIONS, ['userId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);
        // SET NULL, not CASCADE: dropping a site must cost the attribution, not
        // the submissions that came in through it.
        $this->addForeignKey(null, Table::SUBMISSIONS, ['submittedSiteId'], CraftTable::SITES, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::SUBMISSION_NOTES, ['submissionId'], Table::SUBMISSIONS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::SUBMISSION_NOTES, ['userId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::SUBMISSION_HISTORY, ['submissionId'], Table::SUBMISSIONS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::SUBMISSION_HISTORY, ['userId'], CraftTable::ELEMENTS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::NOTIFICATIONS, ['formId'], Table::FORMS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::NOTIFICATIONS_LOG, ['notificationId'], Table::NOTIFICATIONS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::NOTIFICATIONS_LOG, ['submissionId'], Table::SUBMISSIONS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::NOTIFICATIONS_LOG, ['formId'], Table::FORMS, ['id'], 'SET NULL', null);
        $this->addForeignKey(null, Table::INTEGRATIONS_LOG, ['integrationId'], Table::INTEGRATIONS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::INTEGRATIONS_LOG, ['submissionId'], Table::SUBMISSIONS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::RELATIONS, ['submissionId'], Table::SUBMISSIONS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::RELATIONS, ['elementId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
    }

    /**
     * Seeds the four statuses a new install starts with.
     *
     * They go in through the service, so project config is written and the rows
     * appear as its read model - the same path the control panel takes. An
     * install being brought up from an existing project config already has its
     * statuses in there, and applying that config creates the rows; seeding
     * here as well would double them, so it doesn't.
     */
    private function insertDefaultData(): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        if ($projectConfig->getIsApplyingExternalChanges() || $projectConfig->get(Statuses::CONFIG_KEY) !== null) {
            return;
        }

        $statuses = [
            ['New', 'new', 'green', true],
            ['In Review', 'inReview', 'orange', false],
            ['Approved', 'approved', 'teal', false],
            ['Rejected', 'rejected', 'red', false],
        ];

        $service = Plugin::getInstance()->getStatuses();

        foreach ($statuses as [$name, $handle, $color, $isDefault]) {
            $service->saveStatus(new Status([
                'name' => $name,
                'handle' => $handle,
                'color' => $color,
                'isDefault' => $isDefault,
                'uid' => StringHelper::UUID(),
            ]), false);
        }
    }
}
