<?php

declare(strict_types=1);

namespace bytesof\formable\records;

use bytesof\formable\db\Table;
use craft\db\ActiveRecord;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property int $submissionId
 * @property int|null $userId
 * @property array<string, mixed>|string|null $changes
 * @property string $dateCreated
 * @property string $dateUpdated
 *
 * @internal
 */
final class SubmissionHistoryRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SUBMISSION_HISTORY;
    }

    public function getSubmission(): ActiveQueryInterface
    {
        return $this->hasOne(SubmissionRecord::class, ['id' => 'submissionId']);
    }
}
