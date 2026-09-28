<?php

declare(strict_types=1);

namespace bytesof\formable\models;

use Craft;
use craft\base\Model;

/**
 * One selectable option on a dropdown, radio, checkboxes or multi-select
 * field.
 *
 * @api
 */
final class FieldOption extends Model
{
    public string $label = '';
    public string $value = '';
    public bool $isDefault = false;
    public bool $isDisabled = false;

    public function __toString(): string
    {
        return $this->label;
    }

    /**
     * Options are commonly authored with a label only; fall back to the label
     * so an option is never submitted as an empty value.
     */
    public function getValue(): string
    {
        return $this->value !== '' ? $this->value : $this->label;
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['label'], 'required'],
            [['label', 'value'], 'string', 'max' => 255],
            [['isDefault', 'isDisabled'], 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributeLabels(): array
    {
        return [
            'label' => Craft::t('formable', 'Label'),
            'value' => Craft::t('formable', 'Value'),
        ];
    }
}
