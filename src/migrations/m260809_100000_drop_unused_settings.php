<?php

declare(strict_types=1);

namespace bytesof\formable\migrations;

use Craft;
use craft\db\Migration;

/**
 * Drops the `retentionDays` and `enableIpTracking` plugin settings.
 *
 * Both were plugin-wide controls on the settings screen that nothing ever read:
 * retention is per-form (`FormSettings::$dataRetentionDays`) and IP capture is
 * per-form too (`FormSettings::$collectIp`, gated additionally on Craft's own
 * `storeUserIps`). An admin who set "Data retention: 30" was told submissions
 * would be purged and they were not - see
 * internal/decisions/0021-global-settings-are-not-form-defaults.md.
 *
 * The properties are gone from the model, so Craft would ignore the stored
 * values anyway; this removes them from project config so the YAML stops
 * carrying keys nothing can explain.
 */
final class m260809_100000_drop_unused_settings extends Migration
{
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        // Another environment applying this same change through its own YAML
        // has already had it removed upstream; re-removing it here would write
        // to config mid-apply.
        if ($projectConfig->getIsApplyingExternalChanges()) {
            return true;
        }

        $projectConfig->remove('plugins.formable.settings.retentionDays');
        $projectConfig->remove('plugins.formable.settings.enableIpTracking');

        return true;
    }

    public function safeDown(): bool
    {
        // Nothing to restore - the settings had no readers, so no behaviour
        // depended on their values.
        return true;
    }
}
