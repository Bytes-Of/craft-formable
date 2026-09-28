<?php

declare(strict_types=1);

namespace bytesof\formable\base;

use craft\base\Model;

/**
 * Single-attribute model used to run a field's value rules in isolation.
 *
 * Fields declare rules without an attribute name; binding them here (rather
 * than to a shared submission model) lets a field be validated on its own -
 * which is what the per-field unit tests and the conditional-logic engine
 * both need.
 *
 * @internal
 */
final class FieldValueModel extends Model
{
    public mixed $value = null;

    /** @var array<int, array<mixed>> */
    private array $_rules = [];

    private string $_label = '';

    /**
     * @param array<int, array<mixed>> $rules Attribute-less rule specs
     */
    public function __construct(mixed $value, array $rules, string $label, array $config = [])
    {
        $this->value = $value;
        $this->_rules = $rules;
        $this->_label = $label;

        parent::__construct($config);
    }

    /**
     * @return array<int, string>
     */
    public function attributes(): array
    {
        return ['value'];
    }

    /**
     * @return array<string, string>
     */
    public function attributeLabels(): array
    {
        return ['value' => $this->_label];
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        return array_map(static function(array $rule): array {
            $validator = $rule[0];
            unset($rule[0]);

            // array_merge keeps the string-keyed validator options and
            // renumbers the positional [attributes, validator] head.
            return array_merge([['value'], $validator], $rule);
        }, $this->_rules);
    }
}
