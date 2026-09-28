<?php

declare(strict_types=1);

namespace bytesof\formable\migrations;

use bytesof\formable\db\Table;
use craft\db\Migration;
use craft\db\Table as CraftTable;

/**
 * Records which site a submission came from.
 *
 * Forms stay global in 1.0 - this is only the attribution half of multi-site,
 * and it is additive so it can land without waiting for the rest. See
 * internal/decisions/0019-submissions-record-their-site.md for what the other
 * half costs.
 *
 * Existing rows keep a null site: there is no honest way to guess which site an
 * already-stored submission arrived through, and inventing the primary site
 * would make "came from the primary site" unfalsifiable.
 *
 * Additive and guarded - a fresh install runs the (updated) Install migration
 * first, so the column may already exist.
 */
final class m260809_000000_submission_site extends Migration
{
    public function safeUp(): bool
    {
        $submissions = $this->db->getTableSchema(Table::SUBMISSIONS);

        if ($submissions !== null && !isset($submissions->columns['submittedSiteId'])) {
            $this->addColumn(Table::SUBMISSIONS, 'submittedSiteId', (string)$this->integer()->null()->after('formData'));
            $this->createIndex(null, Table::SUBMISSIONS, ['submittedSiteId'], false);
            $this->addForeignKey(null, Table::SUBMISSIONS, ['submittedSiteId'], CraftTable::SITES, ['id'], 'SET NULL', null);
        }

        return true;
    }

    public function safeDown(): bool
    {
        $submissions = $this->db->getTableSchema(Table::SUBMISSIONS);

        if ($submissions !== null && isset($submissions->columns['submittedSiteId'])) {
            $this->dropColumn(Table::SUBMISSIONS, 'submittedSiteId');
        }

        return true;
    }
}
