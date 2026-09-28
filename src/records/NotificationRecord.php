<?php

declare(strict_types=1);

namespace bytesof\formable\records;

use bytesof\formable\db\Table;
use craft\db\ActiveRecord;

/**
 * @property int $id
 * @property int $formId
 * @property string $name
 * @property bool $enabled
 * @property string|null $recipients
 * @property string|null $cc
 * @property string|null $bcc
 * @property string|null $replyTo
 * @property string|null $fromName
 * @property string|null $fromEmail
 * @property string|null $subject
 * @property string|null $body
 * @property bool $attachFiles
 * @property array<string, mixed>|string|null $conditions
 * @property int $sortOrder
 *
 * @internal
 */
final class NotificationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::NOTIFICATIONS;
    }
}
