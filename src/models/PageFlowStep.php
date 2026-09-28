<?php

declare(strict_types=1);

namespace bytesof\formable\models;

/**
 * The intent a move through a form carries.
 *
 * A posted form names one of these in a hidden field its nav buttons set; a
 * headless caller names the same one as an argument. Anything unrecognised is
 * a submit, which is what a form with no JavaScript and no nav does.
 *
 * @internal
 */
final class PageFlowStep
{
    public const SUBMIT = 'submit';
    public const NEXT = 'next';
    public const BACK = 'back';
    public const SAVE = 'save';

    /**
     * @return array<int, string>
     */
    public static function all(): array
    {
        return [self::SUBMIT, self::NEXT, self::BACK, self::SAVE];
    }

    /**
     * Narrows an untrusted step name to one the flow knows, defaulting to
     * submit.
     */
    public static function normalize(mixed $step): string
    {
        return is_string($step) && in_array($step, self::all(), true) ? $step : self::SUBMIT;
    }
}
