<?php

declare(strict_types=1);

namespace bytesof\formable\models;

/**
 * A form import payload parsed and validated into the pieces a save needs,
 * with the database left untouched.
 *
 * {@see \bytesof\formable\services\FormTransfer::parseImport()} builds one from an
 * on-disk export; {@see \bytesof\formable\services\FormTransfer::applyImport()}
 * turns a valid one into a saved {@see \bytesof\formable\elements\Form}. Splitting the
 * parse from the write keeps the serialization round-trip unit-testable without
 * a booted Craft, and lets the console command print a diff summary (and honour
 * `--dry-run`) before anything is written.
 *
 * @internal
 */
final class FormImportResult
{
    /**
     * @param array<int, array<string, mixed>> $pages normalized layout
     * @param array<int, array<string, mixed>> $notifications each in the builder/config shape
     * @param array<mixed> $translations per-site string overrides, keyed by site UID
     * @param array<int, string> $errors human-readable problems, empty when valid
     */
    public function __construct(
        public readonly bool $isValid,
        public readonly string $title,
        public readonly string $handle,
        public readonly bool $enabled,
        public readonly FormSettings $settings,
        public readonly array $pages,
        public readonly array $notifications,
        public readonly array $translations,
        public readonly array $errors,
    ) {
    }

    /**
     * How many value-collecting and layout fields the layout carries - the
     * count the import summary reports.
     */
    public function fieldCount(): int
    {
        $count = 0;

        foreach ($this->pages as $page) {
            foreach (is_array($page['rows'] ?? null) ? $page['rows'] : [] as $row) {
                $fields = is_array($row) && is_array($row['fields'] ?? null) ? $row['fields'] : [];
                $count += count($fields);
            }
        }

        return $count;
    }
}
