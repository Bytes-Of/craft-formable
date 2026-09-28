<?php

declare(strict_types=1);

namespace bytesof\formable\models;

use Craft;
use craft\base\Model;

/**
 * One clause of a conditional-logic rule set: “field X operator value”.
 *
 * The operator vocabulary here is the contract between the PHP evaluator
 * ({@see \bytesof\formable\services\Conditions}) and the JavaScript one
 * (`web/assets/shared/conditions.ts`). Adding an operator means adding it in
 * both places and in `tests/fixtures/conditions.json`, which is what proves
 * they still agree.
 *
 * @internal
 */
final class ConditionRule extends Model
{
    public const OPERATOR_EQ = 'eq';
    public const OPERATOR_NEQ = 'neq';
    public const OPERATOR_CONTAINS = 'contains';
    public const OPERATOR_NOT_CONTAINS = 'notContains';
    public const OPERATOR_STARTS_WITH = 'startsWith';
    public const OPERATOR_ENDS_WITH = 'endsWith';
    public const OPERATOR_GT = 'gt';
    public const OPERATOR_GTE = 'gte';
    public const OPERATOR_LT = 'lt';
    public const OPERATOR_LTE = 'lte';
    public const OPERATOR_EMPTY = 'isEmpty';
    public const OPERATOR_NOT_EMPTY = 'isNotEmpty';

    /**
     * The handle of the field being tested.
     *
     * Handles, not field IDs: conditions are evaluated against a flat map of
     * submitted values, which is keyed by handle. Renaming a handle in the
     * builder rewrites the rules that point at it, and a rule left pointing
     * at an unknown handle is rejected server-side. Already-stored answers
     * are re-keyed by the queued `RenameFieldHandles` job the form save
     * starts, so a rename is followed by the data too.
     */
    public string $field = '';

    public string $operator = self::OPERATOR_EQ;

    /**
     * The value to compare against. Always authored as a string - the
     * operators coerce as needed, so a number field compared with `gt` works
     * without the builder having to know the field's type.
     */
    public string $value = '';

    /**
     * Operators that ignore {@see $value} entirely.
     *
     * @return array<int, string>
     */
    public static function unaryOperators(): array
    {
        return [self::OPERATOR_EMPTY, self::OPERATOR_NOT_EMPTY];
    }

    /**
     * @return array<int, string>
     */
    public static function operators(): array
    {
        return [
            self::OPERATOR_EQ,
            self::OPERATOR_NEQ,
            self::OPERATOR_CONTAINS,
            self::OPERATOR_NOT_CONTAINS,
            self::OPERATOR_STARTS_WITH,
            self::OPERATOR_ENDS_WITH,
            self::OPERATOR_GT,
            self::OPERATOR_GTE,
            self::OPERATOR_LT,
            self::OPERATOR_LTE,
            self::OPERATOR_EMPTY,
            self::OPERATOR_NOT_EMPTY,
        ];
    }

    /**
     * Operator labels for the builder's rule editor.
     *
     * @return array<int, array{value: string, label: string, unary: bool}>
     */
    public static function operatorDefinitions(): array
    {
        $labels = [
            self::OPERATOR_EQ => Craft::t('formable', 'is'),
            self::OPERATOR_NEQ => Craft::t('formable', 'is not'),
            self::OPERATOR_CONTAINS => Craft::t('formable', 'contains'),
            self::OPERATOR_NOT_CONTAINS => Craft::t('formable', 'does not contain'),
            self::OPERATOR_STARTS_WITH => Craft::t('formable', 'starts with'),
            self::OPERATOR_ENDS_WITH => Craft::t('formable', 'ends with'),
            self::OPERATOR_GT => Craft::t('formable', 'is greater than'),
            self::OPERATOR_GTE => Craft::t('formable', 'is greater than or equal to'),
            self::OPERATOR_LT => Craft::t('formable', 'is less than'),
            self::OPERATOR_LTE => Craft::t('formable', 'is less than or equal to'),
            self::OPERATOR_EMPTY => Craft::t('formable', 'is empty'),
            self::OPERATOR_NOT_EMPTY => Craft::t('formable', 'is not empty'),
        ];

        $unary = self::unaryOperators();

        return array_map(
            static fn(string $operator): array => [
                'value' => $operator,
                'label' => $labels[$operator],
                'unary' => in_array($operator, $unary, true),
            ],
            self::operators(),
        );
    }

    /**
     * Builds a rule from stored JSON, coercing anything unexpected to a shape
     * the evaluator can handle rather than throwing.
     *
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self
    {
        $rule = new self();
        $rule->field = is_string($config['field'] ?? null) ? $config['field'] : '';
        $operator = is_string($config['operator'] ?? null) ? $config['operator'] : '';
        $rule->operator = in_array($operator, self::operators(), true) ? $operator : self::OPERATOR_EQ;

        $value = $config['value'] ?? '';

        // Booleans go through the same mapping the evaluator uses for field
        // values ({@see \bytesof\formable\services\Conditions::toList()}), so a
        // rule authored against a checkbox compares the way it reads.
        $rule->value = match (true) {
            is_bool($value) => $value ? '1' : '',
            is_scalar($value) => (string)$value,
            default => '',
        };

        return $rule;
    }

    /**
     * The rule in its stored form.
     *
     * Not `toArray()`: that's Craft's Arrayable contract, whose signature
     * carries field/expand arguments this has no use for.
     *
     * @return array{field: string, operator: string, value: string}
     */
    public function toConfig(): array
    {
        return [
            'field' => $this->field,
            'operator' => $this->operator,
            'value' => $this->value,
        ];
    }

    /**
     * Whether the rule is complete enough to evaluate.
     *
     * An incomplete rule (no field chosen yet) is dropped rather than treated
     * as false - a half-built rule in the builder shouldn't silently hide the
     * field it's attached to.
     */
    public function isComplete(): bool
    {
        return $this->field !== '';
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['field'], 'required'];
        $rules[] = [['field', 'value'], 'string'];
        $rules[] = [['operator'], 'in', 'range' => self::operators()];

        return $rules;
    }
}
