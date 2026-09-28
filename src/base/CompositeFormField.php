<?php

declare(strict_types=1);

namespace bytesof\formable\base;

use Craft;

/**
 * Base for fields made of several named sub-inputs that are stored together
 * as one associative value (name, address).
 *
 * Sub-inputs are toggleable and individually requirable, so one field can
 * cover both "just a first and last name" and a full postal address.
 *
 * @api
 */
abstract class CompositeFormField extends FormField
{
    /**
     * Per-sub-field overrides, keyed by sub-field handle:
     * `['middleName' => ['enabled' => false, 'required' => false, 'label' => '…']]`
     *
     * @var array<string, array<string, mixed>>
     */
    public array $subFieldConfig = [];

    public static function group(): string
    {
        return self::GROUP_ADVANCED;
    }

    /**
     * The sub-inputs this field can render, in order. Each entry supports
     * `label`, `enabled`, `required`, `autocomplete` and `type`.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public static function defineSubFields(): array;

    /**
     * The sub-fields with author overrides applied, disabled ones removed.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getSubFields(): array
    {
        $subFields = [];

        foreach (static::defineSubFields() as $handle => $definition) {
            $definition = array_merge([
                'enabled' => true,
                'required' => false,
                'type' => 'text',
                'autocomplete' => null,
            ], $definition, $this->subFieldConfig[$handle] ?? []);

            if ($definition['enabled'] === true) {
                $subFields[$handle] = $definition;
            }
        }

        return $subFields;
    }

    public function usesFieldset(): bool
    {
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return [
            [
                'name' => 'subFieldConfig',
                'type' => 'subFields',
                'label' => Craft::t('formable', 'Sub-fields'),
                'instructions' => Craft::t('formable', 'Choose which parts to collect, and which are required.'),
                'subFields' => static::defineSubFields(),
            ],
        ];
    }

    /**
     * Sub-field requirements are checked here rather than as rules because
     * each sub-value lives inside the field's single array value.
     *
     * @return array<int, string>
     */
    protected function findValueErrors(mixed $value): array
    {
        $errors = [];

        if (!is_array($value)) {
            $value = [];
        }

        foreach ($this->getSubFields() as $handle => $definition) {
            $isRequired = $this->required || $definition['required'] === true;
            $subValue = $value[$handle] ?? '';

            if ($isRequired && (!is_string($subValue) || trim($subValue) === '')) {
                $errors[] = Craft::t('formable', '{label} is required.', [
                    'label' => $this->subFieldLabel($handle, $definition),
                ]);
            }
        }

        return $errors;
    }

    /**
     * @param array<string, mixed> $definition
     */
    protected function subFieldLabel(string $handle, array $definition): string
    {
        $label = $definition['label'] ?? $handle;

        return is_string($label) ? $label : $handle;
    }

    public function normalizeValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach (array_keys($this->getSubFields()) as $handle) {
            $subValue = $value[$handle] ?? null;
            $normalized[$handle] = is_scalar($subValue) ? trim((string)$subValue) : '';
        }

        return $normalized;
    }

    public function serializeValue(mixed $value): mixed
    {
        return $this->normalizeValue($value);
    }

    public function valueToString(mixed $value): string
    {
        $normalized = $this->normalizeValue($value);

        if (!is_array($normalized)) {
            return '';
        }

        return implode($this->valueSeparator(), array_filter(
            $normalized,
            static fn(mixed $part): bool => is_string($part) && $part !== '',
        ));
    }

    protected function valueSeparator(): string
    {
        return ' ';
    }

    /**
     * Composite fields collect nothing until at least one sub-field is on.
     *
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['subFieldConfig'], 'validateSubFieldConfig', 'skipOnEmpty' => false];

        return $rules;
    }

    public function validateSubFieldConfig(string $attribute): void
    {
        $known = array_keys(static::defineSubFields());

        foreach (array_keys($this->subFieldConfig) as $handle) {
            if (!in_array($handle, $known, true)) {
                $this->addError($attribute, Craft::t('formable', 'Unknown sub-field “{handle}”.', [
                    'handle' => $handle,
                ]));

                return;
            }
        }

        if ($this->getSubFields() === []) {
            $this->addError($attribute, Craft::t('formable', 'Enable at least one sub-field.'));
        }
    }

    /**
     * Beyond the scalar keys {@see FormField::applyTranslation()} handles, a
     * composite field's override carries a `subFields` map keyed by sub-field
     * handle, folded into `subFieldConfig[handle][label]` - the same key
     * {@see getSubFields()} already merges an author's own override from.
     *
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     *
     * @internal
     */
    public static function applyTranslation(array $layout, array $override): array
    {
        $layout = parent::applyTranslation($layout, $override);

        $subFields = $override['subFields'] ?? null;

        if (!is_array($subFields) || $subFields === []) {
            return $layout;
        }

        $subFieldConfig = is_array($layout['subFieldConfig'] ?? null) ? $layout['subFieldConfig'] : [];

        foreach ($subFields as $handle => $label) {
            if (!is_string($handle) || !is_string($label) || $label === '') {
                continue;
            }

            $subFieldConfig[$handle] = array_merge(
                is_array($subFieldConfig[$handle] ?? null) ? $subFieldConfig[$handle] : [],
                ['label' => $label],
            );
        }

        $layout['subFieldConfig'] = $subFieldConfig;

        return $layout;
    }
}
