<?php

declare(strict_types=1);

namespace bytesof\formable\records;

use bytesof\formable\db\Table;
use craft\db\ActiveRecord;

/**
 * One row per integration delivery attempt - the log surfaced in the CP and in
 * the builder's Integrations tab. A failed attempt keeps the payload sent and
 * the raw response, encrypted answers masked, so it can be diagnosed without
 * reproducing it; a successful one keeps neither.
 *
 * @property int $id
 * @property int $integrationId
 * @property int|null $submissionId
 * @property bool $success
 * @property string|null $message
 * @property array<string, mixed>|string|null $payload
 * @property array<string, mixed>|string|null $response
 *
 * @internal
 */
final class IntegrationLogRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::INTEGRATIONS_LOG;
    }
}
