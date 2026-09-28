<?php

declare(strict_types=1);

namespace bytesof\formable\db;

/**
 * Database table name constants, following the craft\db\Table convention.
 *
 * @internal
 */
abstract class Table
{
    public const FORMS = '{{%formable_forms}}';
    public const SUBMISSIONS = '{{%formable_submissions}}';
    public const SUBMISSION_NOTES = '{{%formable_submission_notes}}';
    public const SUBMISSION_HISTORY = '{{%formable_submission_history}}';
    public const NOTIFICATIONS = '{{%formable_notifications}}';
    public const NOTIFICATIONS_LOG = '{{%formable_notifications_log}}';
    public const INTEGRATIONS = '{{%formable_integrations}}';
    public const INTEGRATIONS_LOG = '{{%formable_integrations_log}}';
    public const STATUSES = '{{%formable_statuses}}';
    public const RELATIONS = '{{%formable_relations}}';
    public const UPLOADS = '{{%formable_uploads}}';
}
