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
 * @property string $note
 * @property string $dateCreated
 * @property string $dateUpdated
 *
 * @internal
 */
final class SubmissionNoteRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::SUBMISSION_NOTES;
    }

    public function getSubmission(): ActiveQueryInterface
    {
        return $this->hasOne(SubmissionRecord::class, ['id' => 'submissionId']);
    }
}
