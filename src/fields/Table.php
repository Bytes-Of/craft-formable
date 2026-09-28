<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\FormField;
use bytesof\formable\models\TableColumn;
use Craft;

/**
 * Repeatable rows of typed columns.
 *
 * The stored value is a list of rows, each an associative array keyed by
 * column handle - stable across column reordering and renaming labels.
 *
 * @internal
 */
final class Table extends FormField
{
    public ?int $minRows = null;
    public ?int $maxRows = null;
    public string $addRowLabel = '';

    /**
     * When true the submitter can't add or remove rows - useful for fixed
     * grids like "rate each of these five statements".
     */
    public bool $staticRows = false;

    /** @var array<int, TableColumn> */
    private array $_columns = [];

    public static function displayName(): string
    {
        return Craft::t('formable', 'Table');
    }

    public static function icon(): string
    {
        return 'table';
    }

    public static function group(): string
    {
        return self::GROUP_ADVANCED;
    }

    public function usesFieldset(): bool
    {
        return true;
    }

    /**
     * Rows are unbounded unless the author sets a maximum, and each row carries
     * a cell per column, so a table can grow a multi-page form's session-held
     * progress past what the session backend will store.
     */
    public function isBulky(): bool
    {
        return true;
    }

    /**
     * @return array<int, TableColumn>
     */
    public function getColumns(): array
    {
        return $this->_columns;
    }

    /**
     * @param array<int, TableColumn|array<string, mixed>>|null $columns
     */
    public function setColumns(?array $columns): void
    {
        $this->_columns = [];

        foreach ($columns ?? [] as $column) {
            $this->_columns[] = $column instanceof TableColumn
                ? $column
                : new TableColumn($column);
        }
    }

    /**
     * See the note in OptionsFormField: getter/setter-backed settings must be
     * declared as attributes or their validation rules never run.
     *
     * @return array<int, string>
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), ['columns']);
    }

    /**
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        $fields = parent::fields();

        $fields['columns'] = fn(): array => array_map(
            static fn(TableColumn $column): array => $column->toArray(),
            $this->_columns,
        );

        return $fields;
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['minRows', 'maxRows'], 'integer', 'min' => 0];
        $rules[] = [['maxRows'], 'compare', 'compareAttribute' => 'minRows', 'operator' => '>=', 'type' => 'number', 'skipOnEmpty' => true];
        $rules[] = [['addRowLabel'], 'string'];
        $rules[] = [['staticRows'], 'boolean'];
        $rules[] = [['columns'], 'validateColumns', 'skipOnEmpty' => false];

        return $rules;
    }

    public function validateColumns(string $attribute): void
    {
        if ($this->_columns === []) {
            $this->addError($attribute, Craft::t('formable', 'Add at least one column.'));

            return;
        }

        $seen = [];

        foreach ($this->_columns as $column) {
            if (!$column->validate()) {
                $this->addError($attribute, Craft::t('formable', 'One or more columns are invalid.'));

                return;
            }

            if (isset($seen[$column->handle])) {
                $this->addError($attribute, Craft::t('formable', 'Column handles must be unique - “{handle}” is used more than once.', [
                    'handle' => $column->handle,
                ]));

                return;
            }

            $seen[$column->handle] = true;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return [
            [
                'name' => 'columns',
                'type' => 'tableColumns',
                'label' => Craft::t('formable', 'Columns'),
                'columnTypes' => TableColumn::types(),
            ],
            [
                'name' => 'staticRows',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Fixed Rows'),
                'instructions' => Craft::t('formable', 'Prevent the submitter from adding or removing rows.'),
                'default' => false,
            ],
            [
                'name' => 'minRows',
                'type' => 'number',
                'label' => Craft::t('formable', 'Min Rows'),
                'min' => 0,
            ],
            [
                'name' => 'maxRows',
                'type' => 'number',
                'label' => Craft::t('formable', 'Max Rows'),
                'min' => 1,
            ],
            [
                'name' => 'addRowLabel',
                'type' => 'text',
                'label' => Craft::t('formable', 'Add Row Label'),
                'default' => Craft::t('formable', 'Add a row'),
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function findValueErrors(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $errors = [];
        $count = count($value);

        if ($this->minRows !== null && $count < $this->minRows) {
            $errors[] = Craft::t('formable', 'Enter at least {min, plural, one{# row} other{# rows}}.', [
                'min' => $this->minRows,
            ]);
        }

        if ($this->maxRows !== null && $count > $this->maxRows) {
            $errors[] = Craft::t('formable', 'Enter at most {max, plural, one{# row} other{# rows}}.', [
                'max' => $this->maxRows,
            ]);
        }

        return array_merge($errors, $this->findCellErrors($value));
    }

    /**
     * @param array<int, mixed> $rows
     * @return array<int, string>
     */
    private function findCellErrors(array $rows): array
    {
        $errors = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            foreach ($this->_columns as $column) {
                $cell = $row[$column->handle] ?? null;

                if ($cell === null || $cell === '' || $cell === false) {
                    continue;
                }

                $error = match ($column->type) {
                    TableColumn::TYPE_NUMBER => is_numeric($cell)
                        ? null
                        : Craft::t('formable', 'must be a number'),
                    TableColumn::TYPE_EMAIL => is_string($cell) && filter_var($cell, FILTER_VALIDATE_EMAIL) !== false
                        ? null
                        : Craft::t('formable', 'must be a valid email address'),
                    TableColumn::TYPE_URL => is_string($cell) && filter_var($cell, FILTER_VALIDATE_URL) !== false
                        ? null
                        : Craft::t('formable', 'must be a valid URL'),
                    TableColumn::TYPE_SELECT => in_array($cell, $column->options, true)
                        ? null
                        : Craft::t('formable', 'is not one of the available choices'),
                    default => null,
                };

                if ($error !== null) {
                    $errors[] = Craft::t('formable', 'Row {row}, “{column}” {error}.', [
                        'row' => (int)$index + 1,
                        'column' => $column->label,
                        'error' => $error,
                    ]);
                }
            }
        }

        return $errors;
    }

    public function normalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return [];
        }

        $rows = [];

        foreach ($value as $row) {
            if (!is_array($row)) {
                continue;
            }

            $normalized = [];

            foreach ($this->_columns as $column) {
                $cell = $row[$column->handle] ?? null;

                $normalized[$column->handle] = match ($column->type) {
                    TableColumn::TYPE_CHECKBOX => (bool)$cell,
                    TableColumn::TYPE_NUMBER => is_numeric($cell) ? $cell + 0 : (is_scalar($cell) ? (string)$cell : ''),
                    default => is_scalar($cell) ? trim((string)$cell) : '',
                };
            }

            // Drop rows the submitter left entirely blank rather than storing
            // empty noise or failing a "min rows" check on them.
            if (array_filter($normalized, static fn(mixed $cell): bool => $cell !== '' && $cell !== false) !== []) {
                $rows[] = $normalized;
            }
        }

        return $rows;
    }

    public function serializeValue(mixed $value): mixed
    {
        return $this->normalizeValue($value);
    }

    public function valueToString(mixed $value): string
    {
        $rows = $this->normalizeValue($value);

        if (!is_array($rows) || $rows === []) {
            return '';
        }

        return implode('; ', array_map(
            fn(array $row): string => implode(', ', array_map(
                static fn(TableColumn $column): string => sprintf(
                    '%s: %s',
                    $column->label,
                    is_bool($row[$column->handle] ?? null)
                        ? ($row[$column->handle] ? Craft::t('formable', 'Yes') : Craft::t('formable', 'No'))
                        : (string)($row[$column->handle] ?? ''),
                ),
                $this->_columns,
            )),
            $rows,
        ));
    }

    public function getDefaultValue(): mixed
    {
        return [];
    }

    /**
     * @return array<int, string>
     */
    public static function translatableProperties(): array
    {
        return array_merge(parent::translatableProperties(), ['addRowLabel']);
    }

    /**
     * Beyond the scalar keys {@see FormField::applyTranslation()} handles, a
     * table's override carries a `columns` map keyed by column handle, unlike
     * {@see \bytesof\formable\base\OptionsFormField}'s options which key by
     * value - a column has no equivalent "value" of its own.
     *
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     */
    public static function applyTranslation(array $layout, array $override): array
    {
        $layout = parent::applyTranslation($layout, $override);

        $overrideColumns = $override['columns'] ?? null;
        $columns = $layout['columns'] ?? null;

        if (!is_array($overrideColumns) || $overrideColumns === [] || !is_array($columns)) {
            return $layout;
        }

        $layout['columns'] = array_map(
            static function(mixed $column) use ($overrideColumns): mixed {
                if (!is_array($column) || !is_string($column['handle'] ?? null)) {
                    return $column;
                }

                $value = $overrideColumns[$column['handle']] ?? null;

                if (is_string($value) && $value !== '') {
                    $column['label'] = $value;
                }

                return $column;
            },
            $columns,
        );

        return $layout;
    }
}
