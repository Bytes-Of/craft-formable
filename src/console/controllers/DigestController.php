<?php

declare(strict_types=1);

namespace bytesof\formable\console\controllers;

use bytesof\formable\Plugin;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Sends the weekly activity digest (Pro).
 *
 * `run` is the scheduled entry point: point the server's cron at it - running
 * it hourly is fine - and it sends at most one digest a week, at the day and
 * hour configured in the plugin settings:
 *
 *     0 * * * *  php craft formable/digest/run
 *
 * `send` and `test` are for driving it by hand.
 *
 * @internal
 */
final class DigestController extends Controller
{
    public $defaultAction = 'run';

    /**
     * Send even when the trailing week has no activity, overriding the
     * skip-when-empty setting. Applies to `send`.
     */
    public bool $force = false;

    /**
     * @param string $actionID
     * @return array<int, string>
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'send') {
            $options[] = 'force';
        }

        return $options;
    }

    /**
     * Sends the digest if one is due right now, and records it so the week
     * can't fire twice. Safe to run as often as hourly.
     */
    public function actionRun(): int
    {
        $result = Plugin::getInstance()->getDigest()->run();

        if ($result === null) {
            $this->stdout("No digest was due.\n", Console::FG_GREY);

            return ExitCode::OK;
        }

        if ($result) {
            $this->stdout("Weekly digest sent.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stderr("A digest was due but could not be sent - check the logs.\n", Console::FG_RED);

        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Sends the digest to the configured recipients now, ignoring the schedule.
     * Does not touch the weekly send marker.
     */
    public function actionSend(): int
    {
        if (!Plugin::getInstance()->isPro()) {
            $this->stderr("The weekly digest is a Pro feature.\n", Console::FG_YELLOW);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if (Plugin::getInstance()->getDigest()->send($this->force)) {
            $this->stdout("Weekly digest sent.\n", Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stderr("The digest was not sent - no recipients, nothing to report, or a mailer error. Check the logs.\n", Console::FG_YELLOW);

        return ExitCode::UNSPECIFIED_ERROR;
    }

    /**
     * Sends a test copy of this week's digest to one address.
     */
    public function actionTest(string $email): int
    {
        if (Plugin::getInstance()->getDigest()->sendTest([$email])) {
            $this->stdout(sprintf("Test digest sent to %s.\n", $email), Console::FG_GREEN);

            return ExitCode::OK;
        }

        $this->stderr("The test digest could not be sent - check the address and the logs.\n", Console::FG_RED);

        return ExitCode::UNSPECIFIED_ERROR;
    }
}
