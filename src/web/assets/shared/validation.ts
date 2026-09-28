/**
 * A narrow, declarative client-side validation engine.
 *
 * This is a deliberate re-implementation of `helpers/Validation.php`, on the
 * same footing as `shared/conditions.ts` and `services/Conditions.php`: a
 * value has to be checked while the submitter is still typing (no round trip
 * available), long before the field's real, authoritative validation
 * (`FormField::validateValue()`) ever runs server-side. The two evaluators
 * here exist purely to stay in step with each other - proven by the shared
 * corpus, `tests/fixtures/validation.json` - not to replace the server.
 *
 * Deliberately narrow: only the rules that can be described declaratively -
 * required, type, length, min/max - not a port of Yii's actual validators.
 * `type`'s patterns are written loose on purpose: something this engine
 * rejects will also fail server-side, but the reverse isn't guaranteed, and
 * that is the safe direction for a client-side preview to be wrong in - a
 * false negative here costs exactly the one round trip the form always
 * needed before this existed. A false positive (blocking a submission the
 * server would have accepted) would be a regression, so no rule below is
 * written any stricter than that.
 */

import { NUMERIC } from './conditions';

export type ValidationType = 'email' | 'url' | 'number';

export type ValidationRuleCode =
  'required' | 'type' | 'minLength' | 'maxLength' | 'min' | 'max';

export interface ValidationSpec {
  required: boolean;
  type: ValidationType | null;
  minLength: number | null;
  maxLength: number | null;
  min: number | null;
  max: number | null;
}

const TYPES: ValidationType[] = ['email', 'url', 'number'];

/**
 * Coerces a stored (or hand-written) spec into the canonical shape. Mirrors
 * `normalizeSet()` in `shared/conditions.ts` - an unrecognised `type` or a
 * non-finite bound falls back to "not set" rather than throwing.
 */
export function normalizeSpec(input: unknown): ValidationSpec {
  const raw = (input ?? {}) as Partial<ValidationSpec> &
    Record<string, unknown>;

  return {
    required: raw.required === true,
    type: TYPES.includes(raw.type as ValidationType)
      ? (raw.type as ValidationType)
      : null,
    minLength: normalizeBound(raw.minLength),
    maxLength: normalizeBound(raw.maxLength),
    min: normalizeBound(raw.min),
    max: normalizeBound(raw.max),
  };
}

function normalizeBound(value: unknown): number | null {
  return typeof value === 'number' && Number.isFinite(value) ? value : null;
}

/**
 * A loose email shape: something on each side of an `@`, and a dot after it.
 * Not RFC 5322 - that isn't the point. It exists to catch "forgot the @" and
 * "forgot the domain", the same two mistakes every inline-validating form
 * catches, without rejecting anything Yii's own (also permissive) validator
 * would accept.
 */
const EMAIL_PATTERN = /^\S+@\S+\.\S+$/;

/** Mirrors `Url::normalizeValue()` - a bare "example.com" gets a scheme before it's tested. */
const URL_SCHEME_PATTERN = /^[a-z][a-z0-9+.-]*:/i;

/** scheme://host.tld - loose on purpose (see the file header), but requires a real host with a dot in it. */
const URL_PATTERN = /^[a-z][a-z0-9+.-]*:\/\/[^\s/]+\.[^\s/]+/i;

/**
 * Tests one raw control value against a spec, returning the rule codes that
 * failed - mirrors `Validation::evaluate()` on the PHP side exactly, down to
 * the order errors are collected in.
 *
 * The value is trimmed first, matching `Text::normalizeValue()` and friends:
 * whitespace alone reads as empty, and length/type/numeric checks run against
 * the trimmed string. A non-required, empty value short-circuits to no
 * errors at all - `required` is the only rule an absent value is the business
 * of, the same `skipOnEmpty` behaviour Yii's own validators default to.
 */
export function evaluate(
  spec: ValidationSpec,
  rawValue: string,
): ValidationRuleCode[] {
  const value = rawValue.trim();

  if (spec.required && value === '') {
    return ['required'];
  }

  if (value === '') {
    return [];
  }

  const codes: ValidationRuleCode[] = [];

  if (spec.type !== null && !matchesType(spec.type, value)) {
    codes.push('type');
  }

  if (spec.minLength !== null && length(value) < spec.minLength) {
    codes.push('minLength');
  }

  if (spec.maxLength !== null && length(value) > spec.maxLength) {
    codes.push('maxLength');
  }

  if ((spec.min !== null || spec.max !== null) && NUMERIC.test(value)) {
    const number = Number.parseFloat(value);

    if (spec.min !== null && number < spec.min) {
      codes.push('min');
    }

    if (spec.max !== null && number > spec.max) {
      codes.push('max');
    }
  }

  return codes;
}

function matchesType(type: ValidationType, value: string): boolean {
  switch (type) {
    case 'email':
      return EMAIL_PATTERN.test(value);
    case 'url':
      return URL_PATTERN.test(withScheme(value));
    case 'number':
      return NUMERIC.test(value);
    default:
      return true;
  }
}

function withScheme(value: string): string {
  return URL_SCHEME_PATTERN.test(value) ? value : `https://${value}`;
}

/**
 * Unicode code-point count, not UTF-16 code units - matches PHP's `mb_strlen`
 * on a UTF-8 string closely enough that an astral character (most emoji)
 * counts once on both sides, not twice.
 */
function length(value: string): number {
  return [...value].length;
}
