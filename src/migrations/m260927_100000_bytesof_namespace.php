<?php

declare(strict_types=1);

namespace bytesof\formable\migrations;

use bytesof\formable\db\Table;
use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\Json;
use craft\services\ProjectConfig;
use yii\db\JsonExpression;

/**
 * Rewrites every stored `vivid\formable\…` class name to `bytesof\formable\…`.
 *
 * 1.0.0 renamed the package and namespace because Packagist's `vivid` vendor
 * belongs to an unrelated publisher, so the plugin could not be published under
 * it. See internal/decisions/0107. Only pre-release installs are affected;
 * everything here is a no-op on a fresh one.
 *
 * Class names are matched as exact values rather than with a SQL `LIKE`,
 * because escaping a backslash in a `LIKE` pattern differs between MySQL and
 * Postgres and would silently match nothing on one of them.
 */
final class m260927_100000_bytesof_namespace extends Migration
{
    private const LEGACY_PREFIX = 'vivid\\formable\\';

    private const PREFIX = 'bytesof\\formable\\';

    /**
     * The element, widget and field-layout types that can appear in Craft's own
     * tables, as exact old => new pairs.
     *
     * @var array<string, string>
     */
    private const TYPES = [
        'vivid\\formable\\elements\\Form' => 'bytesof\\formable\\elements\\Form',
        'vivid\\formable\\elements\\Submission' => 'bytesof\\formable\\elements\\Submission',
        'vivid\\formable\\widgets\\RecentSubmissions' => 'bytesof\\formable\\widgets\\RecentSubmissions',
    ];

    public function safeUp(): bool
    {
        $this->rewriteTypeColumns(self::TYPES);
        $this->rewriteFormLayouts(self::LEGACY_PREFIX, self::PREFIX);
        $this->rewriteQueuedJobs(self::LEGACY_PREFIX, self::PREFIX);
        $this->rewriteElementSources(self::TYPES);

        return true;
    }

    public function safeDown(): bool
    {
        $types = array_flip(self::TYPES);

        $this->rewriteTypeColumns($types);
        $this->rewriteFormLayouts(self::PREFIX, self::LEGACY_PREFIX);
        $this->rewriteQueuedJobs(self::PREFIX, self::LEGACY_PREFIX);
        $this->rewriteElementSources($types);

        return true;
    }

    /**
     * @param array<string, string> $types
     */
    private function rewriteTypeColumns(array $types): void
    {
        foreach ([CraftTable::ELEMENTS, CraftTable::WIDGETS, CraftTable::FIELDLAYOUTS] as $table) {
            if ($this->db->getTableSchema($table) === null) {
                continue;
            }

            foreach ($types as $old => $new) {
                $this->update($table, ['type' => $new], ['type' => $old], [], false);
            }
        }
    }

    /**
     * A form's `pages` blob stores each field's type as `static::class`, and a
     * composite field nests its sub-fields, so the whole decoded structure is
     * walked rather than a fixed `pages[].rows[].fields[]` path.
     */
    private function rewriteFormLayouts(string $from, string $to): void
    {
        if ($this->db->getTableSchema(Table::FORMS) === null) {
            return;
        }

        $rows = (new Query())
            ->select(['id', 'pages'])
            ->from([Table::FORMS])
            ->all($this->db);

        foreach ($rows as $row) {
            $pages = $row['pages'];

            if (!is_string($pages)) {
                continue;
            }

            $decoded = Json::decodeIfJson($pages);

            if (!is_array($decoded)) {
                continue;
            }

            // The decoded structures are compared, not the stored text: a
            // backslash is escaped inside JSON, so the column holds
            // `vivid\\formable\\` where the decoded value holds
            // `vivid\formable\`, and searching the raw column for the old
            // namespace would find nothing. Re-encoding to compare would not
            // work either - MySQL normalizes a JSON column's whitespace, so
            // the round-trip differs even when no field type changed.
            $rewritten = $this->rewriteStrings($decoded, $from, $to);

            if ($rewritten === $decoded) {
                continue;
            }

            // Wrapped rather than pre-encoded: `pages` is a JSON column, so handing it
            // a string would store the JSON escaped inside a JSON string.
            $this->update(Table::FORMS, ['pages' => new JsonExpression($rewritten)], ['id' => $row['id']], [], false);
        }
    }

    /**
     * @param array<mixed> $value
     * @return array<mixed>
     */
    private function rewriteStrings(array $value, string $from, string $to): array
    {
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = $this->rewriteStrings($item, $from, $to);
            } elseif (is_string($item) && str_starts_with($item, $from)) {
                $value[$key] = $to . substr($item, strlen($from));
            }
        }

        return $value;
    }

    /**
     * Craft serializes a queued job with PHP's own format, which prefixes every
     * class and string with its byte length - and the new namespace is two
     * bytes longer, so the payload can't be string-replaced without recomputing
     * them. The blob is not unserialized to do this: the old class no longer
     * exists, so it would come back as `__PHP_Incomplete_Class`.
     */
    private function rewriteQueuedJobs(string $from, string $to): void
    {
        if ($this->db->getTableSchema(CraftTable::QUEUE) === null) {
            return;
        }

        $rows = (new Query())
            ->select(['id', 'job'])
            ->from([CraftTable::QUEUE])
            ->all($this->db);

        foreach ($rows as $row) {
            $job = is_resource($row['job']) ? (string)stream_get_contents($row['job']) : (string)$row['job'];

            if (!str_contains($job, $from)) {
                continue;
            }

            $rewritten = $this->rewriteSerialized($job, $from, $to);

            $this->db->createCommand()
                ->update(CraftTable::QUEUE, ['job' => $rewritten], ['id' => $row['id']])
                ->execute();
        }
    }

    /**
     * Only class-name-shaped payloads are touched (letters, digits, underscores
     * and separators), so a `LIKE`-style match can't run past a closing quote
     * into the rest of the blob.
     */
    private function rewriteSerialized(string $job, string $from, string $to): string
    {
        $pattern = '/([Os]):\d+:"(' . preg_quote($from, '/') . '[A-Za-z0-9_\\\\]*)"/';

        return (string)preg_replace_callback(
            $pattern,
            static function(array $match) use ($from, $to): string {
                $name = $to . substr($match[2], strlen($from));

                return $match[1] . ':' . strlen($name) . ':"' . $name . '"';
            },
            $job,
        );
    }

    /**
     * Project config keys element sources by element type, so the type change
     * moves the key rather than a value.
     *
     * @param array<string, string> $types
     */
    private function rewriteElementSources(array $types): void
    {
        $projectConfig = Craft::$app->getProjectConfig();

        foreach ($types as $old => $new) {
            $sources = $projectConfig->get(ProjectConfig::PATH_ELEMENT_SOURCES . '.' . $old);

            if ($sources === null) {
                continue;
            }

            $projectConfig->set(
                ProjectConfig::PATH_ELEMENT_SOURCES . '.' . $new,
                $sources,
                "Move Formable’s element sources to the “{$new}” element type",
            );
            $projectConfig->remove(ProjectConfig::PATH_ELEMENT_SOURCES . '.' . $old);
        }
    }
}
