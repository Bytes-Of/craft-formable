<?php

declare(strict_types=1);

namespace bytesof\formable\helpers;

/**
 * WCAG contrast maths for a user-chosen colour. Moved from
 * {@see \Tests\Unit\BorderContrastTest} so the palette work (0075) and the
 * shipped-default border check share one implementation instead of two.
 *
 * @internal
 */
final class Color
{
    public static function isValidHex(string $value): bool
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $value) === 1;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    public static function hexToRgb(string $hex): array
    {
        $hex = ltrim($hex, '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return [
            hexdec(substr($hex, 0, 2)),
            hexdec(substr($hex, 2, 2)),
            hexdec(substr($hex, 4, 2)),
        ];
    }

    public static function relativeLuminance(string $hex): float
    {
        $toLinear = static function(int $channel): float {
            $normalized = $channel / 255;

            return $normalized <= 0.03928
                ? $normalized / 12.92
                : (($normalized + 0.055) / 1.055) ** 2.4;
        };

        [$r, $g, $b] = array_map($toLinear, self::hexToRgb($hex));

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    public static function contrastRatio(string $hexA, string $hexB): float
    {
        $lighter = max(self::relativeLuminance($hexA), self::relativeLuminance($hexB));
        $darker = min(self::relativeLuminance($hexA), self::relativeLuminance($hexB));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    /**
     * Which of white or near-black reads better on top of `$hex`, per design
     * rule 2: the user picks a brand fill, PHP picks the text colour that
     * sits on it - this is never exposed as a control of its own. Near-black
     * matches `--formable-color-neutral-950`, the same value the shipped
     * dark-mode button text already resolves to.
     */
    public static function readableTextOn(string $hex): string
    {
        $white = '#ffffff';
        $nearBlack = '#121212';

        return self::contrastRatio($hex, $white) >= self::contrastRatio($hex, $nearBlack)
            ? $white
            : $nearBlack;
    }
}
