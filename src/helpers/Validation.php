<?php

declare(strict_types=1);

namespace bytesof\formable\helpers;

/**
 * A narrow, declarative client-side validation engine, PHP side.
 *
 * Mirrors `web/assets/shared/validation.ts` on the same footing as
 * {@see \bytesof\formable\services\Conditions} mirrors `shared/conditions.ts`:
 * a value has to be checked while the submitter is still typing, long before
 * a field's real, authoritative validation
 * ({@see \bytesof\formable\base\FormField::validateValue()}) ever runs
 * server-side. This class has no caller of its own in production - its only
 * job is to be the other half of the parity proof, held together by the
 * shared corpus, `tests/fixtures/validation.json`. `getValidationSpecConfig()`
 * on {@see \bytesof\formable\base\FormField} is what actually reaches the
 * browser; this is what proves the browser's copy of the logic agrees with
 * it.
 *
 * Deliberately narrow: only the rules that can be described declaratively -
 * required, type, length, min/max - not a port of Yii's actual validators.
 * `type`'s patterns are written loose on purpose: something this engine
 * rejects will also fail the real, server-side validator, but the reverse
 * isn't guaranteed, and that is the safe direction for a client-side preview
 * to be wrong in - a false negative here costs exactly the one round trip the
 * form always needed before this existed. A false positive (blocking a
 * submission the server would have accepted) would be a regression, so no
 * rule below is written any stricter than that.
 *
 * Pure and framework-free, so it is unit tested directly.
 *
 * @internal
 */
final class Validation
{
    public const TYPE_EMAIL = 'email';
    public const TYPE_URL = 'url';
    public const TYPE_NUMBER = 'number';

    public const ERROR_REQUIRED = 'required';
    public const ERROR_TYPE = 'type';
    public const ERROR_MIN_LENGTH = 'minLength';
    public const ERROR_MAX_LENGTH = 'maxLength';
    public const ERROR_MIN = 'min';
    public const ERROR_MAX = 'max';

    /**
     * A loose email shape: something on each side of an `@`, and a dot after
     * it. Not RFC 5322 - that isn't the point. See the class docblock.
     */
    private const EMAIL_PATTERN = '/^\S+@\S+\.\S+$/';

    /** Mirrors `Url::normalizeValue()` - a bare "example.com" gets a scheme before it's tested. */
    private const URL_SCHEME_PATTERN = '/^[a-z][a-z0-9+.\-]*:/i';

    /** scheme://host.tld - loose on purpose, but requires a real host with a dot in it. */
    private const URL_PATTERN = '/^[a-z][a-z0-9+.\-]*:\/\/[^\s\/]+\.[^\s\/]+/i';

    /**
     * PHP's `is_numeric` after trimming, expressed as a pattern - the same
     * one {@see \bytesof\formable\services\Conditions::anyNumeric()} uses,
     * mirrored (not shared - the two classes have no reason to depend on each
     * other) in `shared/conditions.ts` and, from there, `shared/validation.ts`.
     */
    private const NUMERIC_PATTERN = '/^[+-]?(\d+(\.\d*)?|\.\d+)([eE][+-]?\d+)?$/';

    /**
     * Tests one raw value against a spec, returning the rule codes that
     * failed - mirrors `evaluate()` on the JavaScript side exactly, down to
     * the order errors are collected in.
     *
     * The value is trimmed first, matching `Text::normalizeValue()` and
     * friends: whitespace alone reads as empty, and length/type/numeric
     * checks run against the trimmed string. A non-required, empty value
     * short-circuits to no errors at all - `required` is the only rule an
     * absent value is the business of, the same `skipOnEmpty` behaviour Yii's
     * own validators default to.
     *
     * @param array{required: bool, type: ?string, minLength: ?int, maxLength: ?int, min: ?float, max: ?float} $spec
     * @return array<int, string>
     */
    public static function evaluate(array $spec, string $rawValue): array
    {
        $value = trim($rawValue);
        $required = $spec['required'] ?? false;

        if ($required && $value === '') {
            return [self::ERROR_REQUIRED];
        }

        if ($value === '') {
            return [];
        }

        $errors = [];
        $type = $spec['type'] ?? null;

        if ($type !== null && !self::matchesType($type, $value)) {
            $errors[] = self::ERROR_TYPE;
        }

        $minLength = $spec['minLength'] ?? null;
        $maxLength = $spec['maxLength'] ?? null;
        $length = mb_strlen($value);

        if ($minLength !== null && $length < $minLength) {
            $errors[] = self::ERROR_MIN_LENGTH;
        }

        if ($maxLength !== null && $length > $maxLength) {
            $errors[] = self::ERROR_MAX_LENGTH;
        }

        $min = $spec['min'] ?? null;
        $max = $spec['max'] ?? null;

        if (($min !== null || $max !== null) && self::isNumeric($value)) {
            $number = (float)$value;

            if ($min !== null && $number < $min) {
                $errors[] = self::ERROR_MIN;
            }

            if ($max !== null && $number > $max) {
                $errors[] = self::ERROR_MAX;
            }
        }

        return $errors;
    }

    private static function matchesType(string $type, string $value): bool
    {
        return match ($type) {
            self::TYPE_EMAIL => preg_match(self::EMAIL_PATTERN, $value) === 1,
            self::TYPE_URL => preg_match(self::URL_PATTERN, self::withScheme($value)) === 1,
            self::TYPE_NUMBER => self::isNumeric($value),
            default => true,
        };
    }

    private static function withScheme(string $value): string
    {
        return preg_match(self::URL_SCHEME_PATTERN, $value) === 1 ? $value : "https://$value";
    }

    private static function isNumeric(string $value): bool
    {
        return preg_match(self::NUMERIC_PATTERN, $value) === 1;
    }
}
