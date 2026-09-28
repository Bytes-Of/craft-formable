<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\FormField;
use Craft;

/**
 * Single consent checkbox ("I agree to the terms").
 *
 * Kept distinct from Checkboxes because consent has different semantics: the
 * stored value is a boolean, and "required" means "must be checked".
 *
 * @internal
 */
final class Agree extends FormField
{
    /**
     * The statement shown beside the checkbox. Supports inline HTML so it can
     * link to a policy - purified on render, not trusted outright, since
     * authoring needs only `formable:saveForms`. See internal/decisions/0099.
     */
    public string $description = '';

    public bool $defaultValue = false;

    public static function displayName(): string
    {
        return Craft::t('formable', 'Agree');
    }

    public static function icon(): string
    {
        return 'check';
    }

    public static function group(): string
    {
        return self::GROUP_CHOICE;
    }

    /**
     * The consent statement beside the checkbox is the field's label - the
     * wrapper must not add a second one.
     */
    public function rendersOwnLabel(): bool
    {
        return true;
    }

    /**
     * @return array<int, string>
     */
    public static function translatableProperties(): array
    {
        return array_merge(parent::translatableProperties(), ['description']);
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['description'], 'string'];
        $rules[] = [['defaultValue'], 'boolean'];

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return [
            [
                'name' => 'description',
                'type' => 'richText',
                'label' => Craft::t('formable', 'Description'),
                'instructions' => Craft::t('formable', 'The statement shown beside the checkbox. Inline links are allowed.'),
            ],
            [
                'name' => 'defaultValue',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Checked by Default'),
                'default' => false,
            ],
        ];
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineValueValidationRules(): array
    {
        $rules = [['boolean']];

        if ($this->required) {
            // A bare 'required' rule passes on false (Yii only treats null,
            // '' and [] as empty), which would let an unchecked consent box
            // through. requiredValue compares against true instead.
            $rules[] = ['required', 'requiredValue' => true, 'message' => Craft::t('formable', 'You must accept to continue.')];
        }

        return $rules;
    }

    public function normalizeValue(mixed $value): mixed
    {
        if (is_string($value)) {
            return !in_array(strtolower($value), ['', '0', 'false', 'no', 'off'], true);
        }

        return (bool)$value;
    }

    public function serializeValue(mixed $value): mixed
    {
        return $this->normalizeValue($value);
    }

    public function getDefaultValue(): mixed
    {
        return $this->defaultValue;
    }
}
