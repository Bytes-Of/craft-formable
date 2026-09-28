<?php

declare(strict_types=1);

namespace bytesof\formable\records;

use bytesof\formable\db\Table;
use craft\db\ActiveRecord;
use craft\records\Element;
use yii\db\ActiveQueryInterface;

/**
 * @property int $id
 * @property string $handle
 * @property array<string, mixed>|string|null $pages
 * @property array<string, mixed>|string|null $settings
 * @property array<string, mixed>|string|null $translations
 * @property int|null $defaultStatusId
 *
 * @internal
 */
final class FormRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::FORMS;
    }

    public function getElement(): ActiveQueryInterface
    {
        return $this->hasOne(Element::class, ['id' => 'id']);
    }
}
