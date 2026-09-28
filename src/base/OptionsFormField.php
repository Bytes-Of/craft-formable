<?php

declare(strict_types=1);

namespace bytesof\formable\base;

use bytesof\formable\models\FieldOption;
use Craft;

/**
 * Base for fields that present a fixed set of author-defined options
 * (dropdown, radio, checkboxes, multi-select).
 *
 * @api
 */
abstract class OptionsFormField extends FormField
{
    /** @var array<int, FieldOption> */
    private array $_options = [];

    public static function group(): string
    {
        return self::GROUP_CHOICE;
    }

    /**
     * Whether more than one option may be selected.
     */
    public static function isMultiple(): bool
    {
        return false;
    }

    /**
     * @return array<int, FieldOption>
     */
    public function getOptions(): array
    {
        return $this->_options;
    }

    /**
     * @param array<int, FieldOption|array<string, mixed>>|null $options
     */
    public function setOptions(?array $options): void
    {
        $this->_options = [];

        foreach ($options ?? [] as $option) {
            $this->_options[] = $option instanceof FieldOption
                ? $option
                : new FieldOption($option);
        }
    }

    /**
     * The submittable values, used for both validation and rendering.
     *
     * @return array<int, string>
     */
    public function getOptionValues(): array
    {
        return array_map(
            static fn(FieldOption $option): string => $option->getValue(),
            $this->_options,
        );
    }

    /**
     * `options` is backed by a getter/setter rather than a public property,
     * so it has to be declared explicitly - Yii only validates attributes it
     * knows about, and would silently skip the rule otherwise.
     *
     * @return array<int, string>
     */
    public function attributes(): array
    {
        return array_merge(parent::attributes(), ['options']);
    }

    /**
     * @return array<string, mixed>
     */
    public function fields(): array
    {
        $fields = parent::fields();

        $fields['options'] = fn(): array => array_map(
            static fn(FieldOption $option): array => $option->toArray(),
            $this->_options,
        );

        return $fields;
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        // skipOnEmpty:false - an empty option list is precisely the case this
        // rule exists to catch, and Yii would otherwise skip it.
        $rules[] = [['options'], 'validateOptions', 'skipOnEmpty' => false];

        return $rules;
    }

    public function validateOptions(string $attribute): void
    {
        if ($this->_options === []) {
            $this->addError($attribute, Craft::t('formable', 'Add at least one option.'));

            return;
        }

        $seen = [];

        foreach ($this->_options as $option) {
            if (!$option->validate()) {
                $this->addError($attribute, Craft::t('formable', 'One or more options are invalid.'));

                return;
            }

            $value = $option->getValue();

            if (isset($seen[$value])) {
                $this->addError($attribute, Craft::t('formable', 'Option values must be unique - “{value}” is used more than once.', [
                    'value' => $value,
                ]));

                return;
            }

            $seen[$value] = true;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return [
            [
                'name' => 'options',
                'type' => 'options',
                'label' => Craft::t('formable', 'Options'),
                'instructions' => Craft::t('formable', 'Define the choices available to the submitter.'),
                'multiple' => static::isMultiple(),
            ],
        ];
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineValueValidationRules(): array
    {
        $range = $this->getOptionValues();

        if (static::isMultiple()) {
            return [
                ['each', 'rule' => ['in', 'range' => $range]],
            ];
        }

        return [
            ['in', 'range' => $range],
        ];
    }

    public function normalizeValue(mixed $value): mixed
    {
        if (static::isMultiple()) {
            if ($value === null || $value === '') {
                return [];
            }

            return array_values(array_map(
                static fn(mixed $item): string => is_scalar($item) ? (string)$item : '',
                is_array($value) ? $value : [$value],
            ));
        }

        if (is_array($value)) {
            $value = reset($value);
        }

        return is_scalar($value) ? (string)$value : null;
    }

    public function getDefaultValue(): mixed
    {
        $defaults = array_values(array_map(
            static fn(FieldOption $option): string => $option->getValue(),
            array_filter($this->_options, static fn(FieldOption $option): bool => $option->isDefault),
        ));

        if (static::isMultiple()) {
            return $defaults;
        }

        return $defaults[0] ?? null;
    }

    /**
     * Beyond the scalar keys {@see FormField::applyTranslation()} handles, an
     * options field's override carries a `options` map keyed the same way
     * {@see \bytesof\formable\models\FieldOption::getValue()} keys the option
     * itself - the value if it has one, the label otherwise.
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

        $overrideOptions = $override['options'] ?? null;
        $options = $layout['options'] ?? null;

        if (!is_array($overrideOptions) || $overrideOptions === [] || !is_array($options)) {
            return $layout;
        }

        $layout['options'] = array_map(
            static function(mixed $option) use ($overrideOptions): mixed {
                if (!is_array($option)) {
                    return $option;
                }

                $key = (new FieldOption($option))->getValue();
                $value = $overrideOptions[$key] ?? null;

                if (is_string($value) && $value !== '') {
                    $option['label'] = $value;
                }

                return $option;
            },
            $options,
        );

        return $layout;
    }
}
