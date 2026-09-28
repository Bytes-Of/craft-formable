/**
 * The conditional-logic engine, JavaScript side.
 *
 * This is a deliberate re-implementation of `services/Conditions.php`, not a
 * port of convenience: a condition has to hold while the submitter is typing
 * (no round trip available) *and* when the submission is validated (no
 * trusting the browser). Neither engine can be the only one.
 *
 * The two are held together by `tests/fixtures/conditions.json` - a corpus of
 * cases both test suites run. Any change here needs the same change in the PHP
 * engine and a fixture case that would have caught the difference.
 *
 * Parity notes:
 * - Comparisons are case-sensitive. Case folding is locale-dependent in ways
 *   `mb_strtolower` and `String.prototype.toLowerCase` don't agree on.
 * - A JSON object is flattened over its values, because PHP decodes objects to
 *   associative arrays and has no way to tell them from lists. That's also the
 *   sensible reading for a Table field: "does any cell match".
 * - Numbers are stringified with the default rules of each language, which
 *   diverge only in exponent notation (PHP renders 1e25 as `1.0E+25`). Form
 *   values arrive from the DOM as strings, so this is reachable only through
 *   the programmatic API.
 */

export const ACTION_SHOW = 'show';
export const ACTION_HIDE = 'hide';

export const MATCH_ALL = 'all';
export const MATCH_ANY = 'any';

export type ConditionAction = typeof ACTION_SHOW | typeof ACTION_HIDE;
export type ConditionMatch = typeof MATCH_ALL | typeof MATCH_ANY;

export type ConditionOperator =
  | 'eq'
  | 'neq'
  | 'contains'
  | 'notContains'
  | 'startsWith'
  | 'endsWith'
  | 'gt'
  | 'gte'
  | 'lt'
  | 'lte'
  | 'isEmpty'
  | 'isNotEmpty';

export interface ConditionRule {
  field: string;
  operator: ConditionOperator;
  value: string;
}

export interface ConditionSet {
  enabled: boolean;
  action: ConditionAction;
  match: ConditionMatch;
  rules: ConditionRule[];
}

export type ValueMap = Record<string, unknown>;

const OPERATORS: ConditionOperator[] = [
  'eq',
  'neq',
  'contains',
  'notContains',
  'startsWith',
  'endsWith',
  'gt',
  'gte',
  'lt',
  'lte',
  'isEmpty',
  'isNotEmpty',
];

/**
 * Coerces a stored (or hand-written) condition set into the canonical shape.
 *
 * Mirrors `ConditionSet::fromArray()`: unknown actions, operators and match
 * modes fall back to their defaults, and incomplete rules are dropped rather
 * than treated as false.
 */
export function normalizeSet(input: unknown): ConditionSet {
  const raw = (input ?? {}) as Partial<ConditionSet> & Record<string, unknown>;

  const rules = Array.isArray(raw.rules) ? raw.rules : [];

  return {
    enabled: raw.enabled === true,
    action: raw.action === ACTION_HIDE ? ACTION_HIDE : ACTION_SHOW,
    match: raw.match === MATCH_ANY ? MATCH_ANY : MATCH_ALL,
    rules: rules
      .map((rule) => normalizeRule(rule))
      .filter((rule): rule is ConditionRule => rule !== null),
  };
}

function normalizeRule(input: unknown): ConditionRule | null {
  if (typeof input !== 'object' || input === null) {
    return null;
  }

  const raw = input as Record<string, unknown>;
  const field = typeof raw.field === 'string' ? raw.field : '';

  if (field === '') {
    return null;
  }

  const operator = raw.operator as ConditionOperator;
  const value = raw.value;

  return {
    field,
    operator: OPERATORS.includes(operator) ? operator : 'eq',
    value: isScalar(value) ? stringify(value) : '',
  };
}

/** Whether a set actually constrains anything. */
export function isActive(set: ConditionSet): boolean {
  return set.enabled && set.rules.length > 0;
}

/**
 * Whether an element carrying this set should be visible.
 *
 * An inactive set is always visible - the "no conditions" default.
 */
export function isVisible(set: ConditionSet, values: ValueMap): boolean {
  if (!isActive(set)) {
    return true;
  }

  const matched = matches(set, values);

  return set.action === ACTION_SHOW ? matched : !matched;
}

/** Whether the rules match, before the show/hide action is applied. */
export function matches(set: ConditionSet, values: ValueMap): boolean {
  if (set.rules.length === 0) {
    return true;
  }

  const any = set.match === MATCH_ANY;

  for (const rule of set.rules) {
    // Short-circuits both ways: `any` needs one hit, `all` one miss.
    if (testRule(rule, values[rule.field]) === any) {
      return any;
    }
  }

  return !any;
}

/** Tests one rule against one field's value. */
export function testRule(rule: ConditionRule, value: unknown): boolean {
  let list = toList(value);
  const target = rule.value;

  if (rule.operator === 'isEmpty') {
    return isEmptyList(list);
  }

  if (rule.operator === 'isNotEmpty') {
    return !isEmptyList(list);
  }

  // An absent value still compares as the empty string, or `is ""` and
  // `is not "x"` would both be false for a field nobody touched.
  if (list.length === 0) {
    list = [''];
  }

  switch (rule.operator) {
    // A multi-value field matches when any of its selections does - what "is"
    // means to an author looking at a list of checkboxes.
    case 'eq':
      return list.some((entry) => entry === target);
    case 'neq':
      return !list.some((entry) => entry === target);
    case 'contains':
      return list.some((entry) => target !== '' && entry.includes(target));
    case 'notContains':
      return !list.some((entry) => target !== '' && entry.includes(target));
    case 'startsWith':
      return list.some((entry) => target !== '' && entry.startsWith(target));
    case 'endsWith':
      return list.some((entry) => target !== '' && entry.endsWith(target));
    case 'gt':
      return anyNumeric(list, target, (a, b) => a > b);
    case 'gte':
      return anyNumeric(list, target, (a, b) => a >= b);
    case 'lt':
      return anyNumeric(list, target, (a, b) => a < b);
    case 'lte':
      return anyNumeric(list, target, (a, b) => a <= b);
    default:
      return false;
  }
}

/**
 * Flattens any field value into a list of strings.
 *
 * Mirrors `Conditions::toList()`.
 */
export function toList(value: unknown): string[] {
  if (value === null || value === undefined) {
    return [];
  }

  // False maps to "" rather than "0": PHP normalizes an unchecked Agree field
  // to `false`, while the browser posts nothing for an unchecked box, so this
  // engine sees no value at all. Both then read as empty.
  if (typeof value === 'boolean') {
    return [value ? '1' : ''];
  }

  if (typeof value === 'number') {
    // NaN and Infinity have no comparable string form in either language.
    return Number.isFinite(value) ? [stringify(value)] : [];
  }

  if (typeof value === 'string') {
    return [value];
  }

  if (Array.isArray(value)) {
    return value.flatMap((item) => toList(item));
  }

  if (typeof value === 'object') {
    // PHP decodes JSON objects to associative arrays, which its `toList`
    // flattens over the values - match that rather than reading a key order
    // the two languages don't have to agree on.
    return Object.values(value as Record<string, unknown>).flatMap((item) =>
      toList(item),
    );
  }

  return [];
}

function isScalar(value: unknown): value is string | number | boolean {
  return (
    typeof value === 'string' ||
    typeof value === 'number' ||
    typeof value === 'boolean'
  );
}

function stringify(value: string | number | boolean): string {
  if (typeof value === 'boolean') {
    return value ? '1' : '';
  }

  return String(value);
}

function isEmptyList(list: string[]): boolean {
  return !list.some((entry) => entry.trim() !== '');
}

/**
 * PHP's `is_numeric` after trimming, expressed as a pattern.
 *
 * Written out rather than leaning on `Number(entry)`, which accepts things PHP
 * doesn't (`0x10`, `Infinity`, `''`) and would drift the two engines apart.
 *
 * Exported so `shared/validation.ts` reads from this one definition too,
 * rather than a second copy of the same pattern drifting from it.
 */
export const NUMERIC = /^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/;

function anyNumeric(
  list: string[],
  target: string,
  test: (a: number, b: number) => boolean,
): boolean {
  const trimmedTarget = target.trim();

  if (!NUMERIC.test(trimmedTarget)) {
    return false;
  }

  const targetNumber = Number.parseFloat(trimmedTarget);

  return list.some((entry) => {
    const trimmed = entry.trim();

    return (
      NUMERIC.test(trimmed) && test(Number.parseFloat(trimmed), targetNumber)
    );
  });
}

export interface ResolvableField {
  handle: string;
  conditions: unknown;
}

export interface ResolvablePage {
  conditions: unknown;
  fields: ResolvableField[];
}

export interface Resolution {
  pages: boolean[];
  fields: Record<string, boolean>;
  values: ValueMap;
}

/**
 * Works out which pages and fields are visible for a set of values.
 *
 * A single pass in document order, matching `Conditions::resolve()` - see the
 * long comment there for why it isn't a fixed-point iteration. A field
 * resolved hidden has its value blanked for everything evaluated after it, so
 * chained conditions collapse correctly.
 */
export function resolve(pages: ResolvablePage[], values: ValueMap): Resolution {
  const pageVisibility: boolean[] = [];
  const fieldVisibility: Record<string, boolean> = {};
  const effective: ValueMap = { ...values };

  pages.forEach((page, index) => {
    const pageVisible = isVisible(normalizeSet(page.conditions), effective);
    pageVisibility[index] = pageVisible;

    page.fields.forEach((field) => {
      if (field.handle === '') {
        return;
      }

      // A field on a hidden page is hidden whatever its own rules say.
      const visible =
        pageVisible && isVisible(normalizeSet(field.conditions), effective);

      fieldVisibility[field.handle] = visible;

      if (!visible) {
        effective[field.handle] = null;
      }
    });
  });

  return { pages: pageVisibility, fields: fieldVisibility, values: effective };
}
