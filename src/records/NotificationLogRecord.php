<?php

declare(strict_types=1);

namespace bytesof\formable\records;

use bytesof\formable\db\Table;
use craft\db\ActiveRecord;

/**
 * One row per delivery attempt, successful or not - the email log surfaced in
 * the CP.
 *
 * @property int $id
 * @property int|null $notificationId
 * @property int|null $submissionId
 * @property int|null $formId
 * @property bool $success
 * @property string|null $recipients
 * @property string|null $subject
 * @property string|null $error
 *
 * @internal
 */
final class NotificationLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::NOTIFICATIONS_LOG;
    }
}
