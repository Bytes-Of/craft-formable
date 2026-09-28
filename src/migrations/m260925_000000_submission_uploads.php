<?php

declare(strict_types=1);

namespace bytesof\formable\migrations;

use bytesof\formable\db\Table;
use craft\db\Migration;

/**
 * Adds the upload ownership table - which assets Formable's own upload
 * pipeline created, and for which submission. See internal/decisions/0101-uploads-and-delivery-logs-go-with-the-submission.md.
 *
 * Uploads made before this migration have no row, so they are never deleted
 * automatically; the table only starts tracking from here.
 */
final class m260925_000000_submission_uploads extends Migration
{
    public function safeUp(): bool
    {
        if ($this->db->tableExists(Table::UPLOADS)) {
            return true;
        }

        (new Install(['db' => $this->db]))->createUploadsTable();

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::UPLOADS);

        return true;
    }
}
