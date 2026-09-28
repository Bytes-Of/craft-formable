<?php

declare(strict_types=1);

namespace bytesof\formable\models;

use Craft;
use craft\base\Model;

/**
 * A conditional-logic rule set, as attached to a field or a page.
 *
 * Stored shape - identical in PHP, in the layout JSON, and in the JavaScript
 * evaluator:
 *
 * ```json
 * {
 *   "enabled": true,
 *   "action": "show",
 *   "match": "all",
 *   "rules": [
 *     { "field": "contactMethod", "operator": "eq", "value": "email" }
 *   ]
 * }
 * ```
 *
 * `action` reads as “{show|hide} this element when {all|any} of these rules
 * match”. An empty or disabled set always resolves to visible, so a field
 * whose author never opened the conditions tab behaves exactly as before.
 *
 * @internal
 */
final class ConditionSet extends Model
{
    public const ACTION_SHOW = 'show';
    public const ACTION_HIDE = 'hide';

    public const MATCH_ALL = 'all';
    public const MATCH_ANY = 'any';

    public bool $enabled = false;

    public string $action = self::ACTION_SHOW;

    public string $match = self::MATCH_ALL;

    /** @var array<int, ConditionRule> */
    private array $_rules = [];

    /**
     * @param array<string, mixed>|null $config
     */
    public static function fromArray(?array $config): self
    {
        $set = new self();

        if ($config === null) {
            return $set;
        }

        $set->enabled = (bool)($config['enabled'] ?? false);

        $action = is_string($config['action'] ?? null) ? $config['action'] : '';
        $set->action = in_array($action, [self::ACTION_SHOW, self::ACTION_HIDE], true)
            ? $action
            : self::ACTION_SHOW;

        $match = is_string($config['match'] ?? null) ? $config['match'] : '';
        $set->match = in_array($match, [self::MATCH_ALL, self::MATCH_ANY], true)
            ? $match
            : self::MATCH_ALL;

        $rules = [];

        foreach (is_array($config['rules'] ?? null) ? $config['rules'] : [] as $ruleConfig) {
            if (is_array($ruleConfig)) {
                $rules[] = ConditionRule::fromArray($ruleConfig);
            }
        }

        $set->setRules($rules);

        return $set;
    }

    /**
     * @return array<int, ConditionRule>
     */
    public function getRules(): array
    {
        return $this->_rules;
    }

    /**
     * @param array<int, ConditionRule> $rules
     */
    public function setRules(array $rules): void
    {
        $this->_rules = array_values(array_filter(
            $rules,
            static fn(ConditionRule $rule): bool => $rule->isComplete(),
        ));
    }

    /**
     * Whether this set actually constrains anything.
     *
     * Both halves matter: the switch has to be on *and* there has to be at
     * least one complete rule. Treating an enabled-but-empty set as active
     * would hide every field an author toggled conditions on before writing
     * the first rule.
     */
    public function isActive(): bool
    {
        return $this->enabled && $this->_rules !== [];
    }

    /**
     * @return array{enabled: bool, action: string, match: string, rules: array<int, array{field: string, operator: string, value: string}>}
     */
    public function toConfig(): array
    {
        return [
            'enabled' => $this->enabled,
            'action' => $this->action,
            'match' => $this->match,
            'rules' => array_map(
                static fn(ConditionRule $rule): array => $rule->toConfig(),
                $this->_rules,
            ),
        ];
    }

    /**
     * The handles this set reads.
     *
     * Used to work out which inputs a conditionally-shown element has to
     * listen to on the front end, and to catch rules pointing at fields that
     * no longer exist.
     *
     * @return array<int, string>
     */
    public function getFieldHandles(): array
    {
        return array_values(array_unique(array_map(
            static fn(ConditionRule $rule): string => $rule->field,
            $this->_rules,
        )));
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['enabled'], 'boolean'];
        $rules[] = [['action'], 'in', 'range' => [self::ACTION_SHOW, self::ACTION_HIDE]];
        $rules[] = [['match'], 'in', 'range' => [self::MATCH_ALL, self::MATCH_ANY]];

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    public function attributeLabels(): array
    {
        return [
            'enabled' => Craft::t('formable', 'Enable Conditions'),
            'action' => Craft::t('formable', 'Action'),
            'match' => Craft::t('formable', 'Match'),
        ];
    }
}
