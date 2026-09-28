<?php

declare(strict_types=1);

namespace bytesof\formable\records;

use bytesof\formable\db\Table;
use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $formId
 * @property int|null $statusId
 * @property int|null $userId
 * @property array<string, mixed>|string|null $formData
 * @property int|null $submittedSiteId
 * @property string|null $ipAddress
 * @property string|null $userAgent
 * @property bool $isIncomplete
 * @property bool $isSpam
 * @property string|null $spamReason
 * @property string|null $resumeToken
 * @property int $pageIndex
 * @property string|null $dateExpired
 *
 * @internal
 */
final class SubmissionRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SUBMISSIONS;
    }

    public function getForm(): ActiveQueryInterface
    {
        return $this->hasOne(FormRecord::class, ['id' => 'formId']);
    }

    public function getNotes(): ActiveQueryInterface
    {
        return $this->hasMany(SubmissionNoteRecord::class, ['submissionId' => 'id']);
    }
}
