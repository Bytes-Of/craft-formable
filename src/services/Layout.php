<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\base\FormField;
use bytesof\formable\fields\Email;
use bytesof\formable\fields\Name;
use bytesof\formable\fields\Textarea;
use bytesof\formable\models\ConditionSet;
use bytesof\formable\Plugin;
use Craft;
use craft\helpers\StringHelper;
use yii\base\Component;

/**
 * Normalizes and validates the page/row/field layout the builder posts.
 *
 * The builder validates as you type, but nothing it sends is trusted: the
 * layout arrives as free-form JSON, so every page, row and field is rebuilt
 * and re-validated here before it reaches the database.
 *
 * @internal
 */
final class Layout extends Component
{
    /**
     * Fields per row. The builder's grid is 12 columns wide and a field needs
     * a usable minimum width, so four is the practical ceiling.
     */
    public const MAX_FIELDS_PER_ROW = 4;

    private ?Fields $_fields = null;

    /**
     * Overrides the field registry this service builds fields through.
     *
     * Exists so unit tests can drive the layout logic without a booted plugin;
     * at runtime the registry is resolved from the plugin instance.
     */
    public function setFields(Fields $fields): void
    {
        $this->_fields = $fields;
    }

    public function getFields(): Fields
    {
        return $this->_fields ??= Plugin::getInstance()->getFields();
    }

    /**
     * Rebuilds the posted layout into the canonical shape, discarding keys we
     * don't recognise and generating IDs for anything created client-side
     * without one.
     *
     * Structure: `pages[] → { id, label, settings, rows[] → { id, fields[] } }`
     *
     * @param mixed $pages
     * @return array<int, array<string, mixed>>
     */
    public function normalizePages(mixed $pages): array
    {
        if (!is_array($pages)) {
            return [];
        }

        $normalized = [];

        foreach ($pages as $page) {
            if (!is_array($page)) {
                continue;
            }

            $normalized[] = [
                'id' => $this->stringValue($page['id'] ?? null) ?: StringHelper::UUID(),
                'label' => $this->stringValue($page['label'] ?? null) ?? '',
                'settings' => $this->normalizePageSettings($page['settings'] ?? null),
                'rows' => $this->normalizeRows($page['rows'] ?? null),
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $rows
     * @return array<int, array<string, mixed>>
     */
    private function normalizeRows(mixed $rows): array
    {
        if (!is_array($rows)) {
            return [];
        }

        $normalized = [];

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $fields = [];

            foreach (is_array($row['fields'] ?? null) ? $row['fields'] : [] as $fieldConfig) {
                if (!is_array($fieldConfig)) {
                    continue;
                }

                $fields[] = $this->normalizeField($fieldConfig);
            }

            // An empty row is a leftover from dragging the last field out of
            // it - drop it rather than persisting an invisible slot.
            if ($fields === []) {
                continue;
            }

            $normalized[] = [
                'id' => $this->stringValue($row['id'] ?? null) ?: StringHelper::UUID(),
                'fields' => $fields,
            ];
        }

        return $normalized;
    }

    /**
     * Round-trips a field config through its own class, so unknown keys are
     * dropped and every value lands on a typed property.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function normalizeField(array $config): array
    {
        if (($config['id'] ?? '') === '') {
            $config['id'] = StringHelper::UUID();
        }

        $layout = $this->getFields()->createField($config)->toLayoutArray();

        // Conditions are opaque to the field itself, so canonicalize them here
        // - dropping half-written rules and unknown operators - rather than
        // storing whatever the builder happened to post.
        $layout['conditions'] = ConditionSet::fromArray($layout['conditions'] ?? null)->toConfig();

        return $layout;
    }

    /**
     * @param mixed $settings
     * @return array<string, mixed>
     */
    private function normalizePageSettings(mixed $settings): array
    {
        $settings = is_array($settings) ? $settings : [];

        // A page's only structured setting so far is its conditions; anything
        // else the builder attaches rides along untouched.
        $settings['conditions'] = ConditionSet::fromArray($settings['conditions'] ?? null)->toConfig();

        return $settings;
    }

    /**
     * The handles of every value-bearing field across the layout - what a
     * submission's value map would actually contain.
     *
     * Used to catch a condition rule that points at a field which was renamed
     * or removed since the rule was written.
     *
     * @param array<int, array<string, mixed>> $pages
     * @return array<int, string>
     */
    public function getFieldHandles(array $pages): array
    {
        return array_values(array_unique($this->getHandlesByFieldId($pages)));
    }

    /**
     * Which value-bearing fields changed handle between two layouts, matched
     * by field id, as `oldHandle => newHandle`.
     *
     * A field id survives a rename and a handle does not, so the id is the
     * only thing that can tell a rename from a delete plus an add. A swap
     * (`a`→`b` while `b`→`a`) and a chain are both reported as they are;
     * {@see renameKeys()} applies the whole map at once so neither collides.
     *
     * @param array<int, array<string, mixed>> $oldPages
     * @param array<int, array<string, mixed>> $newPages
     * @return array<string, string>
     */
    public function detectRenames(array $oldPages, array $newPages): array
    {
        $old = $this->getHandlesByFieldId($oldPages);
        $renames = [];

        foreach ($this->getHandlesByFieldId($newPages) as $fieldId => $handle) {
            $previous = $old[$fieldId] ?? null;

            if ($previous !== null && $previous !== $handle) {
                $renames[$previous] = $handle;
            }
        }

        return $renames;
    }

    /**
     * Re-keys a value map by a rename map, all at once.
     *
     * A key that is being renamed always wins over an unrelated key already
     * sitting under its new name: that one is what a deleted field left
     * behind, and the field being renamed is the one whose answer the author
     * expects to see.
     *
     * @param array<string, mixed> $values
     * @param array<string, string> $renames `oldHandle => newHandle`
     * @return array<string, mixed>
     */
    public function renameKeys(array $values, array $renames): array
    {
        $result = [];

        foreach ($values as $key => $value) {
            if (!isset($renames[$key])) {
                $result[$key] = $value;
            }
        }

        foreach ($renames as $old => $new) {
            if (array_key_exists($old, $values)) {
                $result[$new] = $values[$old];
            }
        }

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $pages
     * @return array<string, string> `fieldId => handle`
     */
    private function getHandlesByFieldId(array $pages): array
    {
        $fieldsService = $this->getFields();
        $handles = [];

        foreach ($pages as $page) {
            $rows = is_array($page['rows'] ?? null) ? $page['rows'] : [];

            foreach ($rows as $row) {
                $rowFields = is_array($row) && is_array($row['fields'] ?? null) ? $row['fields'] : [];

                foreach ($rowFields as $fieldConfig) {
                    if (!is_array($fieldConfig)) {
                        continue;
                    }

                    $type = $this->stringValue($fieldConfig['type'] ?? null) ?? '';

                    if (!$fieldsService->typeExists($type)) {
                        continue;
                    }

                    $field = $fieldsService->createField($fieldConfig);

                    if ($field::hasValue() && $field->handle !== '') {
                        $handles[$this->stringValue($fieldConfig['id'] ?? null) ?? $field->handle] = $field->handle;
                    }
                }
            }
        }

        return $handles;
    }

    /**
     * The error messages for any handle a condition set names that isn't in
     * {@see $knownHandles}.
     *
     * @param array<int, string> $knownHandles
     * @return array<int, string>
     */
    private function unknownHandleErrors(ConditionSet $conditions, array $knownHandles): array
    {
        return array_map(
            static fn(string $handle): string => Craft::t('formable', 'The conditions reference a field that no longer exists (“{handle}”).', [
                'handle' => $handle,
            ]),
            array_diff($conditions->getFieldHandles(), $knownHandles),
        );
    }

    /**
     * Validates a normalized layout.
     *
     * Errors are keyed by page and field ID rather than by index, so the
     * builder can attach them to the right card after the user has reordered
     * things while the request was in flight.
     *
     * @param array<int, array<string, mixed>> $pages
     * @return array{general: array<int, string>, pages: array<string, array<string, array<int, string>>>, fields: array<string, array<string, array<int, string>>>}
     */
    public function validatePages(array $pages): array
    {
        $errors = [
            'general' => [],
            'pages' => [],
            'fields' => [],
        ];

        if ($pages === []) {
            $errors['general'][] = Craft::t('formable', 'A form needs at least one page.');

            return $errors;
        }

        $fieldsService = $this->getFields();
        // Collected up front so a rule can point at a field defined on a
        // later page or row without being flagged as unknown.
        $knownHandles = $this->getFieldHandles($pages);
        $seenHandles = [];
        $seenFieldIds = [];
        $seenPageIds = [];
        $valueFieldCount = 0;

        foreach ($pages as $pageIndex => $page) {
            $pageId = $this->stringValue($page['id'] ?? null) ?? (string)$pageIndex;
            $pageErrors = [];

            if (($this->stringValue($page['label'] ?? null) ?? '') === '') {
                $pageErrors['label'][] = Craft::t('formable', 'Give the page a name.');
            }

            if (isset($seenPageIds[$pageId])) {
                $pageErrors['id'][] = Craft::t('formable', 'Duplicate page ID.');
            }

            $seenPageIds[$pageId] = true;

            $pageSettings = is_array($page['settings'] ?? null) ? $page['settings'] : [];
            $pageConditions = ConditionSet::fromArray(is_array($pageSettings['conditions'] ?? null) ? $pageSettings['conditions'] : null);

            foreach ($this->unknownHandleErrors($pageConditions, $knownHandles) as $message) {
                $pageErrors['conditions'][] = $message;
            }

            $rows = is_array($page['rows'] ?? null) ? $page['rows'] : [];

            foreach ($rows as $row) {
                $rowFields = is_array($row) && is_array($row['fields'] ?? null) ? $row['fields'] : [];

                if (count($rowFields) > self::MAX_FIELDS_PER_ROW) {
                    $pageErrors['rows'][] = Craft::t('formable', 'A row can hold at most {max} fields.', [
                        'max' => self::MAX_FIELDS_PER_ROW,
                    ]);
                }

                foreach ($rowFields as $fieldConfig) {
                    if (!is_array($fieldConfig)) {
                        continue;
                    }

                    $type = $this->stringValue($fieldConfig['type'] ?? null) ?? '';
                    $fieldId = $this->stringValue($fieldConfig['id'] ?? null) ?? '';

                    if (!$fieldsService->typeExists($type)) {
                        $errors['fields'][$fieldId]['type'][] = Craft::t('formable', 'Unknown field type “{type}”.', [
                            'type' => $type,
                        ]);

                        continue;
                    }

                    $field = $fieldsService->createField($fieldConfig);

                    if (isset($seenFieldIds[$fieldId])) {
                        $errors['fields'][$fieldId]['id'][] = Craft::t('formable', 'Duplicate field ID.');
                    }

                    $seenFieldIds[$fieldId] = true;

                    if (!$field->validate()) {
                        foreach ($field->getErrors() as $attribute => $messages) {
                            foreach ($messages as $message) {
                                $errors['fields'][$fieldId][$attribute][] = $message;
                            }
                        }
                    }

                    $fieldConditions = ConditionSet::fromArray(is_array($fieldConfig['conditions'] ?? null) ? $fieldConfig['conditions'] : null);

                    foreach ($this->unknownHandleErrors($fieldConditions, $knownHandles) as $message) {
                        $errors['fields'][$fieldId]['conditions'][] = $message;
                    }

                    if (!$field::hasValue()) {
                        continue;
                    }

                    $valueFieldCount++;

                    // Handles have to be unique across the whole form, not just
                    // the page - submitted values are one flat map.
                    if ($field->handle !== '') {
                        if (isset($seenHandles[$field->handle])) {
                            $errors['fields'][$fieldId]['handle'][] = Craft::t('formable', 'The handle “{handle}” is already used by another field on this form.', [
                                'handle' => $field->handle,
                            ]);
                        }

                        $seenHandles[$field->handle] = true;
                    }
                }
            }

            if ($pageErrors !== []) {
                $errors['pages'][$pageId] = $pageErrors;
            }
        }

        if ($valueFieldCount === 0) {
            $errors['general'][] = Craft::t('formable', 'Add at least one field that collects a value.');
        }

        return $errors;
    }

    /**
     * Whether a validation result carries any errors.
     *
     * @param array{general: array<int, string>, pages: array<string, mixed>, fields: array<string, mixed>} $errors
     */
    public function hasErrors(array $errors): bool
    {
        return $errors['general'] !== [] || $errors['pages'] !== [] || $errors['fields'] !== [];
    }

    /**
     * A starter layout for a brand-new form: one page holding a name, email
     * and message, which is what most first forms turn out to be anyway.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getDefaultPages(): array
    {
        return $this->normalizePages([
            [
                'label' => Craft::t('formable', 'Page 1'),
                'rows' => [
                    // Not `name` - Craft's HandleValidator reserves it.
                    ['fields' => [$this->starterField(Name::class, 'Name', 'fullName')]],
                    ['fields' => [$this->starterField(Email::class, 'Email', 'email')]],
                    ['fields' => [$this->starterField(Textarea::class, 'Message', 'message')]],
                ],
            ],
        ]);
    }

    /**
     * @param class-string<FormField> $type
     * @return array<string, mixed>
     */
    private function starterField(string $type, string $label, string $handle): array
    {
        return [
            'type' => $type,
            'id' => StringHelper::UUID(),
            'label' => Craft::t('formable', $label),
            'handle' => $handle,
            'required' => true,
        ];
    }

    private function stringValue(mixed $value): ?string
    {
        return is_string($value) ? $value : (is_scalar($value) ? (string)$value : null);
    }
}
