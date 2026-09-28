<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\base\FormField;
use bytesof\formable\elements\Form;
use bytesof\formable\models\ConditionRule;
use bytesof\formable\models\ConditionSet;
use bytesof\formable\Plugin;
use yii\base\Component;

/**
 * The conditional-logic engine.
 *
 * There are two evaluators in this plugin - this one and its mirror in
 * `web/assets/shared/conditions.ts` - because a condition has to hold both
 * while the submitter is typing (no round trip) and when the submission is
 * validated (no trusting the browser). They are kept in step by
 * `tests/fixtures/conditions.json`, a shared corpus both test suites run.
 *
 * Two deliberate choices keep that parity achievable:
 *
 * - **Comparisons are case-sensitive string comparisons**, over a value
 *   normalized to a list of strings. Case folding is locale-dependent in ways
 *   PHP's `mb_strtolower` and JavaScript's `toLowerCase` don't agree on for
 *   every alphabet, and a silent disagreement between the two engines is far
 *   worse than an author having to match the case of their own option values.
 * - **Resolution is a single ordered pass** ({@see resolve()}), not a
 *   fixed-point iteration. See that method for why.
 *
 * @internal
 */
final class Conditions extends Component
{
    private ?Fields $_fields = null;

    private ?bool $_enabled = null;

    /**
     * Overrides the field registry, so the engine can be unit tested without a
     * booted plugin. Mirrors {@see Layout::setFields()}.
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
     * Overrides the edition gate, so both the Pro and the bypassed Lite
     * resolution paths can be unit tested without a booted plugin. Mirrors
     * {@see setFields()}.
     */
    public function setEnabled(bool $enabled): void
    {
        $this->_enabled = $enabled;
    }

    /**
     * Whether conditional logic runs at all.
     *
     * It's a Pro feature: in Lite the engine is bypassed entirely by the
     * form-level resolution methods below - every page and field resolves
     * visible, so a form still carrying builder-authored rules from before a
     * downgrade behaves exactly like one with no conditions. Only the
     * form-level methods gate; the pure evaluators stay edition-free so the
     * shared parity corpus keeps exercising the real engine.
     *
     * Reads the active edition unless {@see setEnabled()} has overridden it -
     * unit tests drive the gate that way rather than through a booted plugin.
     */
    public function isEnabled(): bool
    {
        return $this->_enabled ??= Plugin::getInstance()->isPro();
    }

    /**
     * Whether an element carrying this set should be visible.
     *
     * An inactive set is always visible - that's the “no conditions” default,
     * and it's what makes `conditions: {}` on every existing field a no-op.
     *
     * @param array<string, mixed> $values
     */
    public function isVisible(ConditionSet $set, array $values): bool
    {
        if (!$set->isActive()) {
            return true;
        }

        $matched = $this->matches($set, $values);

        return $set->action === ConditionSet::ACTION_SHOW ? $matched : !$matched;
    }

    /**
     * Whether the set's rules match, before the show/hide action is applied.
     *
     * @param array<string, mixed> $values
     */
    public function matches(ConditionSet $set, array $values): bool
    {
        $rules = $set->getRules();

        if ($rules === []) {
            return true;
        }

        $any = $set->match === ConditionSet::MATCH_ANY;

        foreach ($rules as $rule) {
            $result = $this->testRule($rule, $values[$rule->field] ?? null);

            // Short-circuit both ways: `any` needs one hit, `all` one miss.
            if ($result === $any) {
                return $any;
            }
        }

        return !$any;
    }

    /**
     * Tests one rule against one field's value.
     */
    public function testRule(ConditionRule $rule, mixed $value): bool
    {
        $list = self::toList($value);
        $target = $rule->value;

        switch ($rule->operator) {
            case ConditionRule::OPERATOR_EMPTY:
                return self::isEmptyList($list);

            case ConditionRule::OPERATOR_NOT_EMPTY:
                return !self::isEmptyList($list);
        }

        // Everything below compares entry by entry. An absent value still has
        // to compare as the empty string, or `is ""` and `is not "x"` would
        // both come out false for a field the submitter never touched.
        if ($list === []) {
            $list = [''];
        }

        return match ($rule->operator) {
            // A multi-value field (checkboxes, multi-select) matches when *any*
            // of its selections does, which is what “is” means to an author
            // looking at a list of checkboxes.
            ConditionRule::OPERATOR_EQ => self::any($list, static fn(string $v): bool => $v === $target),
            ConditionRule::OPERATOR_NEQ => !self::any($list, static fn(string $v): bool => $v === $target),
            ConditionRule::OPERATOR_CONTAINS => self::any($list, static fn(string $v): bool => self::contains($v, $target)),
            ConditionRule::OPERATOR_NOT_CONTAINS => !self::any($list, static fn(string $v): bool => self::contains($v, $target)),
            ConditionRule::OPERATOR_STARTS_WITH => self::any($list, static fn(string $v): bool => $target !== '' && str_starts_with($v, $target)),
            ConditionRule::OPERATOR_ENDS_WITH => self::any($list, static fn(string $v): bool => $target !== '' && str_ends_with($v, $target)),
            ConditionRule::OPERATOR_GT => self::anyNumeric($list, $target, static fn(float $a, float $b): bool => $a > $b),
            ConditionRule::OPERATOR_GTE => self::anyNumeric($list, $target, static fn(float $a, float $b): bool => $a >= $b),
            ConditionRule::OPERATOR_LT => self::anyNumeric($list, $target, static fn(float $a, float $b): bool => $a < $b),
            ConditionRule::OPERATOR_LTE => self::anyNumeric($list, $target, static fn(float $a, float $b): bool => $a <= $b),
            default => false,
        };
    }

    /**
     * Works out which pages and fields of a form are visible for a given set
     * of values.
     *
     * Resolution is a **single pass in document order**, and a field that has
     * been resolved hidden has its value blanked for every condition evaluated
     * after it. So a chain - show B when A is “yes”, show C when B is “no” -
     * collapses correctly when A changes, because B is resolved before C.
     *
     * A fixed-point iteration would also handle rules that point *backwards*,
     * but it needs cycle detection (two fields can each hide the other), and
     * the iteration order then has to match byte-for-byte in the JavaScript
     * engine or the two disagree on pathological forms. One ordered pass is
     * deterministic, terminates trivially, and covers the way conditions are
     * actually authored: referring to something the submitter has already
     * answered.
     *
     * `$pages` lets a caller resolve against a translated layout (see
     * {@see \bytesof\formable\services\Translations::localizePages()}) rather
     * than the form's raw one - condition rules and visibility never depend
     * on which strings are showing, so the two agree either way, but passing
     * the same array a render already built avoids hydrating a second,
     * untranslated {@see \bytesof\formable\models\FieldMap} for the one request.
     *
     * @param array<string, mixed> $values
     * @param array<int, mixed>|null $pages
     * @return array{pages: array<int, bool>, fields: array<string, bool>, values: array<string, mixed>}
     */
    public function resolve(Form $form, array $values, ?array $pages = null): array
    {
        $pages ??= $form->getPages();

        if (!$this->isEnabled()) {
            return $this->resolveAllVisible($pages, $values);
        }

        return $this->resolvePages($pages, $values);
    }

    /**
     * The resolution a Lite form gets: every page and field visible, values
     * untouched. A pure pages-based method - like {@see resolvePages()} - so
     * the bypassed path is unit-testable without a booted form.
     *
     * @param array<int, mixed> $pages
     * @param array<string, mixed> $values
     * @return array{pages: array<int, bool>, fields: array<string, bool>, values: array<string, mixed>}
     */
    public function resolveAllVisible(array $pages, array $values): array
    {
        $map = $this->getFields()->getFieldMap($pages);
        $pageVisibility = [];
        $fieldVisibility = [];

        foreach ($pages as $pageIndex => $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageVisibility[$pageIndex] = true;

            foreach ($map->forPage($pageIndex)->all() as $field) {
                if (!$field::hasValue() || $field->handle === '') {
                    continue;
                }

                $fieldVisibility[$field->handle] = true;
            }
        }

        return [
            'pages' => $pageVisibility,
            'fields' => $fieldVisibility,
            'values' => $values,
        ];
    }

    /**
     * {@see resolve()}, against a raw layout rather than a form element.
     *
     * Split out so the engine can be driven from the shared fixture corpus
     * without a database.
     *
     * @param array<int, mixed> $pages
     * @param array<string, mixed> $values
     * @return array{pages: array<int, bool>, fields: array<string, bool>, values: array<string, mixed>}
     */
    public function resolvePages(array $pages, array $values): array
    {
        $map = $this->getFields()->getFieldMap($pages);
        $pageVisibility = [];
        $fieldVisibility = [];

        // The value map conditions are evaluated against, with hidden fields
        // progressively blanked out.
        $effective = $values;

        foreach ($pages as $pageIndex => $page) {
            if (!is_array($page)) {
                continue;
            }

            $pageSettings = is_array($page['settings'] ?? null) ? $page['settings'] : [];
            $pageConditions = is_array($pageSettings['conditions'] ?? null) ? $pageSettings['conditions'] : null;
            $pageVisible = $this->isVisible(ConditionSet::fromArray($pageConditions), $effective);

            $pageVisibility[$pageIndex] = $pageVisible;

            foreach ($map->forPage($pageIndex)->all() as $field) {
                if (!$field::hasValue() || $field->handle === '') {
                    continue;
                }

                // A field on a hidden page is hidden regardless of its own
                // rules - otherwise skipping a page would still enforce the
                // required fields on it.
                $visible = $pageVisible
                    && $this->isVisible(ConditionSet::fromArray($field->conditions), $effective);

                $fieldVisibility[$field->handle] = $visible;

                if (!$visible) {
                    $effective[$field->handle] = null;
                }
            }
        }

        return [
            'pages' => $pageVisibility,
            'fields' => $fieldVisibility,
            'values' => $effective,
        ];
    }

    /**
     * The form's value-collecting fields that are visible for these values,
     * keyed by handle.
     *
     * This is what validation runs against: a field the submitter was never
     * shown must not be able to reject their submission for being blank.
     *
     * `$pages` lets a caller validate against a translated layout - see
     * {@see resolve()}'s note on why that's the same answer either way.
     *
     * @param array<string, mixed> $values
     * @param array<int, mixed>|null $pages
     * @return array<string, FormField>
     */
    public function getVisibleFields(Form $form, array $values, ?int $pageIndex = null, ?array $pages = null): array
    {
        $pages ??= $form->getPages();

        // Lite doesn't run conditions, so every value field is "visible" and
        // validation covers the whole page - the same map the renderer uses.
        if (!$this->isEnabled()) {
            return $this->getFields()->getValueFieldsFromLayout($pages, $pageIndex);
        }

        return $this->getVisibleFieldsFromPages($pages, $values, $pageIndex);
    }

    /**
     * {@see getVisibleFields()}, against a raw layout - the Form-free core, so
     * the validation subset can be unit tested without a booted element.
     *
     * @param array<int, mixed> $pages
     * @param array<string, mixed> $values
     * @return array<string, FormField>
     */
    public function getVisibleFieldsFromPages(array $pages, array $values, ?int $pageIndex = null): array
    {
        $visibility = $this->resolvePages($pages, $values)['fields'];
        $fields = [];

        foreach ($this->getFields()->getValueFieldsFromLayout($pages, $pageIndex) as $handle => $field) {
            if ($visibility[$handle] ?? true) {
                $fields[$handle] = $field;
            }
        }

        return $fields;
    }

    /**
     * The indexes of the pages a submitter will actually be walked through.
     *
     * @param array<string, mixed> $values
     * @return array<int, int>
     */
    public function getVisiblePageIndexes(Form $form, array $values): array
    {
        // In Lite no page is ever conditioned out, so the sequence is simply
        // every real page in document order.
        $pages = $this->isEnabled()
            ? array_filter($this->resolvePages($form->getPages(), $values)['pages'])
            : array_filter($form->getPages(), 'is_array');

        return array_keys($pages);
    }

    /**
     * Flattens any field value into a list of strings.
     *
     * The JavaScript engine's `toList` does exactly this; the fixture corpus
     * covers the shapes that make the two easy to get wrong (booleans, nested
     * arrays, numeric strings, objects).
     *
     * @return array<int, string>
     */
    public static function toList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        // False maps to the empty string, not "0". PHP normalizes an unchecked
        // Agree field to `false`, while the browser posts nothing at all for
        // an unchecked box - so the JavaScript engine sees no value. Mapping
        // false to "" makes both read as empty; mapping it to "0" would make
        // `isEmpty` answer differently on the two sides of the same form.
        if (is_bool($value)) {
            return [$value ? '1' : ''];
        }

        if (is_float($value) && !is_finite($value)) {
            // NAN and INF have no comparable string form in either engine.
            return [];
        }

        if (is_int($value) || is_float($value) || is_string($value)) {
            return [(string)$value];
        }

        if (is_array($value)) {
            $list = [];

            foreach ($value as $item) {
                foreach (self::toList($item) as $entry) {
                    $list[] = $entry;
                }
            }

            return $list;
        }

        // An object with no string form (an Asset, a DateTime) can't be
        // compared against an author-typed string, so it reads as no value -
        // `isNotEmpty` is the operator that's meant to catch those.
        if (is_object($value) && method_exists($value, '__toString')) {
            return [(string)$value];
        }

        return [];
    }

    /**
     * @param array<int, string> $list
     */
    private static function isEmptyList(array $list): bool
    {
        foreach ($list as $entry) {
            if (trim($entry) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $list
     * @param callable(string): bool $test
     */
    private static function any(array $list, callable $test): bool
    {
        foreach ($list as $entry) {
            if ($test($entry)) {
                return true;
            }
        }

        return false;
    }

    private static function contains(string $haystack, string $needle): bool
    {
        // An empty needle would otherwise match everything, which reads as a
        // broken rule rather than a universally-true one.
        return $needle !== '' && str_contains($haystack, $needle);
    }

    /**
     * Numeric comparison, false unless both sides really are numbers.
     *
     * A non-numeric value never satisfies `>` or `<` in either engine - the
     * alternative (string comparison as a fallback) puts "10" before "9" and
     * surprises everyone.
     *
     * @param array<int, string> $list
     * @param callable(float, float): bool $test
     */
    private static function anyNumeric(array $list, string $target, callable $test): bool
    {
        if (!is_numeric(trim($target))) {
            return false;
        }

        $targetNumber = (float)trim($target);

        return self::any($list, static function(string $entry) use ($test, $targetNumber): bool {
            $entry = trim($entry);

            return is_numeric($entry) && $test((float)$entry, $targetNumber);
        });
    }
}
