<?php

declare(strict_types=1);

namespace bytesof\formable\base;

use craft\base\ComponentInterface;

/**
 * The contract every Formable field type implements.
 *
 * Fields are plugin-owned components (not Craft fields) that are stored as
 * JSON on the form's `pages` column, so adding a field to a form never
 * touches project config or creates a content column.
 *
 * @api
 */
interface FormFieldInterface extends ComponentInterface
{
    /**
     * SVG icon (or Craft icon name) shown in the builder palette.
     */
    public static function icon(): string;

    /**
     * The palette group this field is listed under.
     */
    public static function group(): string;

    /**
     * Whether the field collects a value from the submitter.
     *
     * False for presentational fields (headings, dividers, HTML blocks), which
     * are rendered but never validated, stored, or exported.
     */
    public static function hasValue(): bool;

    /**
     * Declarative description of the field's own settings, consumed by the
     * builder UI to render the settings drawer without bespoke per-field
     * front-end code.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSettingsSchema(): array;

    /**
     * Yii validation rules applied to the submitted value.
     *
     * Each rule omits the attribute name - it is bound to the field's handle
     * when the rule set is attached to a submission. Index 0 is the validator
     * (a built-in name, a method on the field, or a closure); the remaining
     * keys are validator options.
     *
     * @return array<int, array<mixed>>
     */
    public function getValueValidationRules(): array;

    /**
     * The `name` attribute for the field's primary control.
     *
     * Owned here rather than composed in Twig, so the wire format a field
     * posts under is declared in one place that PHP can also read back.
     *
     * @param string $base The array the form posts its values under
     */
    public function getInputName(string $base = 'fields'): string;

    /**
     * Extra inputs the field renders that are *not* part of its stored value -
     * an email confirmation box, for instance.
     *
     * They post under their own top-level array, keyed by field handle, so
     * they can never collide with (and flatten) the field's own value. Keyed
     * by sub-value key, valued with the full `name` attribute.
     *
     * @return array<string, string>
     */
    public function getSubValueNames(): array;

    /**
     * Validates a raw submitted value, returning error messages.
     *
     * @param array<string, mixed> $subValues Posted sub-values for this field,
     *                                        keyed as {@see getSubValueNames()}
     * @return array<int, string>
     */
    public function validateValue(mixed $value, array $subValues = []): array;

    /**
     * Converts a raw submitted value (POST data, GraphQL input) into the
     * field's working PHP representation.
     */
    public function normalizeValue(mixed $value): mixed;

    /**
     * Converts a normalized value into something JSON-storable for the
     * submission's `formData` column.
     */
    public function serializeValue(mixed $value): mixed;

    /**
     * Human-readable rendering of a stored value, for CP tables, exports and
     * notification emails.
     */
    public function valueToString(mixed $value): string;

    /**
     * The Craft elements a stored value points at, as element IDs in the order
     * they were selected.
     *
     * A field whose value is a reference rather than data - a relation field, a
     * file upload - answers here so the submission can be indexed against those
     * elements. Everything else returns an empty list.
     *
     * @return array<int, int>
     */
    public function getRelatedElementIds(mixed $value): array;

    /**
     * The value used when the form is first rendered.
     */
    public function getDefaultValue(): mixed;

    /**
     * Path to the field's input template, relative to the front-end template
     * pack root, so site-level overrides resolve the same way.
     */
    public function getInputTemplate(): string;

    /**
     * The field as it is persisted in the form layout JSON.
     *
     * @return array<string, mixed>
     */
    public function toLayoutArray(): array;
}
