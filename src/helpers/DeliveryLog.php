<?php

declare(strict_types=1);

namespace bytesof\formable\helpers;

use bytesof\formable\db\Table;
use bytesof\formable\elements\exporters\SubmissionExport;
use bytesof\formable\elements\Submission;
use Craft;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use craft\i18n\Locale;
use DateInterval;
use DateTime;

/**
 * What the notifications log and the integrations log share: deciding which
 * logged attempts the builder may offer to resend, presenting a row, and
 * keeping what a row holds to the minimum for the minimum time.
 *
 * See internal/decisions/0077-a-failed-delivery-is-resent-from-its-log-row.md.
 *
 * @internal
 */
final class DeliveryLog
{
    /**
     * The ids of attempts that failed and are still the newest attempt at their
     * target (one notification or integration, for one submission).
     *
     * Only the newest attempt is offered. An older failure has either been
     * recovered already - a queue retry or an earlier resend succeeded after
     * it - or been superseded by a later failure, which is the one worth acting
     * on. Offering every failed row would invite sending the same lead twice.
     *
     * @param array<int, array{id: int, target: string|null, success: bool}> $attempts
     *   Newest first. A null target marks an attempt that has nothing left to
     *   resend against.
     * @return array<int, int>
     */
    public static function latestFailures(array $attempts): array
    {
        $seen = [];
        $ids = [];

        foreach ($attempts as $attempt) {
            $target = $attempt['target'];

            if ($target === null || isset($seen[$target])) {
                continue;
            }

            $seen[$target] = true;

            if (!$attempt['success']) {
                $ids[] = $attempt['id'];
            }
        }

        return $ids;
    }

    /**
     * Narrows candidate attempts to those whose submission a delivery may still
     * be resent for: one that still exists outside the trash and has not been
     * marked as spam since. Forwarding a submission an operator has since
     * flagged would send spam on to a CRM on their say-so.
     *
     * @param array<int, int> $submissionIds Submission id, keyed by log row id.
     * @return array<int, int> The log row ids that survive.
     */
    public static function withResendableSubmission(array $submissionIds): array
    {
        if ($submissionIds === []) {
            return [];
        }

        $live = array_map('intval', Submission::find()
            ->id(array_values(array_unique($submissionIds)))
            ->status(null)
            ->isSpam(false)
            ->ids());

        return array_keys(array_filter(
            $submissionIds,
            static fn(int $submissionId): bool => in_array($submissionId, $live, true),
        ));
    }

    /**
     * The attempt's time in the viewing user's own time zone - the log stores
     * UTC, and a raw timestamp is exactly what an operator matching a row to a
     * customer's complaint shouldn't have to convert.
     */
    public static function dateLabel(mixed $date): ?string
    {
        $dateTime = DateTimeHelper::toDateTime($date);

        return $dateTime !== false
            ? Craft::$app->getFormatter()->asDatetime($dateTime, Locale::LENGTH_SHORT)
            : null;
    }

    /**
     * Deletes notification and integration log rows older than the given
     * number of days, returning how many went. 0 or less keeps everything.
     */
    public static function purgeOlderThan(int $days): int
    {
        if ($days < 1) {
            return 0;
        }

        $cutoff = Db::prepareDateForDb((new DateTime())->sub(new DateInterval("P{$days}D")));

        return Db::delete(Table::NOTIFICATIONS_LOG, ['<', 'dateCreated', $cutoff])
            + Db::delete(Table::INTEGRATIONS_LOG, ['<', 'dateCreated', $cutoff]);
    }

    /**
     * Masks every occurrence of the given values in a logged payload's
     * strings. An integration shapes its own body - a Slack message embeds an
     * answer mid-sentence - so a value is found wherever it appears rather
     * than matched against the keys it was mapped to. Over-masking a
     * diagnostic copy costs nothing; missing a match would log the one kind of
     * answer the author asked to keep out of plaintext.
     *
     * @param array<mixed> $data
     * @param array<int, string> $values
     * @return array<mixed>
     */
    public static function redact(array $data, array $values): array
    {
        $values = array_values(array_unique(array_filter($values, static fn(string $value): bool => $value !== '')));

        if ($values === []) {
            return $data;
        }

        // Longest first, so a value that contains another is masked whole.
        usort($values, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));

        array_walk_recursive($data, static function(mixed &$item) use ($values): void {
            if (is_string($item)) {
                $item = str_replace($values, SubmissionExport::MASK, $item);
            }
        });

        return $data;
    }

    public static function submissionUrl(?int $submissionId): ?string
    {
        return $submissionId !== null
            ? UrlHelper::cpUrl("formable/submissions/$submissionId")
            : null;
    }
}
