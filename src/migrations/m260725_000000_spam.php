<?php

declare(strict_types=1);

namespace bytesof\formable\migrations;

use bytesof\formable\db\Table;
use craft\db\Migration;

/**
 * Records why a submission was flagged as spam.
 *
 * The `isSpam` flag has been on the submissions table since the install
 * migration; this adds the companion `spamReason` code so the CP's spam queue
 * can show which check caught each one.
 *
 * Additive and guarded - a fresh install runs the updated Install migration
 * first, so the column may already be there.
 */
final class m260725_000000_spam extends Migration
{
    public function safeUp(): bool
    {
        $submissions = $this->db->getTableSchema(Table::SUBMISSIONS);

        if ($submissions !== null && !isset($submissions->columns['spamReason'])) {
            $this->addColumn(Table::SUBMISSIONS, 'spamReason', (string)$this->string()->null()->after('isSpam'));
        }

        return true;
    }

    public function safeDown(): bool
    {
        $submissions = $this->db->getTableSchema(Table::SUBMISSIONS);

        if ($submissions !== null && isset($submissions->columns['spamReason'])) {
            $this->dropColumn(Table::SUBMISSIONS, 'spamReason');
        }

        return true;
    }
}
