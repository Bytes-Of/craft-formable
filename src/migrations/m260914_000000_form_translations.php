<?php

declare(strict_types=1);

namespace bytesof\formable\migrations;

use bytesof\formable\db\Table;
use craft\db\Migration;

/**
 * Adds the translatable-strings blob: per-site overrides for field labels,
 * instructions, option labels and the nine messages/button settings keys,
 * keyed by site UID. See internal/decisions/0081.
 *
 * Additive and guarded - a fresh install runs the (updated) Install migration
 * first, so the column may already exist.
 */
final class m260914_000000_form_translations extends Migration
{
    public function safeUp(): bool
    {
        $forms = $this->db->getTableSchema(Table::FORMS);

        if ($forms !== null && !isset($forms->columns['translations'])) {
            $this->addColumn(Table::FORMS, 'translations', (string)$this->json()->after('settings'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $forms = $this->db->getTableSchema(Table::FORMS);

        if ($forms !== null && isset($forms->columns['translations'])) {
            $this->dropColumn(Table::FORMS, 'translations');
        }

        return true;
    }
}
