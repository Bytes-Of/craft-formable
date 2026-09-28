<?php

declare(strict_types=1);

namespace bytesof\formable\records;

use bytesof\formable\db\Table;
use craft\db\ActiveRecord;

/**
 * One configured integration instance - a named connection of a provider type,
 * with its stored settings. The provider class is rebuilt from `type`, the
 * credentials from `settings`.
 *
 * @property int $id
 * @property string $name
 * @property string $handle
 * @property string $type
 * @property bool $enabled
 * @property array<string, mixed>|string|null $settings
 * @property int $sortOrder
 *
 * @internal
 */
final class IntegrationRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::INTEGRATIONS;
    }
}
