<?php

declare(strict_types=1);

namespace bytesof\formable\records;

use bytesof\formable\db\Table;
use craft\db\ActiveRecord;
use craft\db\SoftDeleteTrait;

/**
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $color
 * @property int $sortOrder
 * @property bool $isDefault
 *
 * @internal
 */
final class StatusRecord extends ActiveRecord
{
    use SoftDeleteTrait;

    public static function tableName(): string
    {
        return Table::STATUSES;
    }
}
