<?php

declare(strict_types=1);

namespace bytesof\formable\helpers;

/**
 * The plain-text part of an HTML email, derived from the HTML itself.
 *
 * A notification's HTML is authored in the builder or comes from a site's own
 * email template, so there is no second source to render a text part from - it
 * has to be read out of the markup. HTML-only mail costs spam score and reads
 * badly in a text-only client, and a bare `strip_tags()` would lose the line
 * breaks and link targets that make the text version usable.
 *
 * Pure and framework-free, so it is unit tested directly.
 *
 * @internal
 */
final class EmailText
{
    private const LINE = "\x01";
    private const PARAGRAPH = "\x02";

    public static function fromHtml(string $html): string
    {
        $text = str_replace([self::LINE, self::PARAGRAPH], '', $html);
        $text = self::replace('~<!--.*?-->~s', '', $text);
        $text = self::replace('~<(head|style|script)\b[^>]*>.*?</\1\s*>~is', '', $text);

        // Whitespace in HTML source is not layout - only markup breaks a line -
        // so it collapses before the markup is read.
        $text = self::replace('~\s+~', ' ', $text);

        $text = preg_replace_callback(
            '~<a\b[^>]*?\shref\s*=\s*(["\'])(.*?)\1[^>]*>(.*?)</a\s*>~is',
            static fn(array $match): string => self::link((string)$match[3], (string)$match[2]),
            $text,
        ) ?? $text;

        $text = self::replace('~<br\b[^>]*>~i', "\n", $text);
        // A header cell followed by a data cell is the all-fields table's row,
        // and "Label: value" is how that row reads without the table.
        $text = self::replace('~</th\s*>\s*(?=<td\b)~i', ': ', $text);
        $text = self::replace('~</t[hd]\s*>~i', ' ', $text);
        $text = self::replace('~<li\b[^>]*>~i', self::LINE . '- ', $text);
        $text = self::replace('~</?(?:tr|div|li|dt|dd)\b[^>]*>~i', self::LINE, $text);
        $text = self::replace('~</?(?:p|h[1-6]|hr|table|ul|ol|dl|blockquote|pre)\b[^>]*>~i', self::PARAGRAPH, $text);

        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Block edges were marked rather than written as newlines so that
        // nesting - a row inside a table inside a layout cell - breaks the line
        // once, not once per element.
        $text = self::replace('~[ \x01\x02]*\x02[ \x01\x02]*~', "\n\n", $text);
        $text = self::replace('~[ \x01]*\x01[ \x01]*~', "\n", $text);
        $text = str_replace("\u{00A0}", ' ', $text);

        $lines = array_map(
            static fn(string $line): string => trim(self::replace('~ {2,}~', ' ', $line)),
            explode("\n", $text),
        );

        return trim(self::replace('~\n{3,}~', "\n\n", implode("\n", $lines)));
    }

    /**
     * A link's text with its target after it, unless the target would only
     * repeat the text or means nothing outside the message.
     *
     * Both arguments are still HTML; what is returned is too, so it is decoded
     * once with everything else rather than exposed to `strip_tags()` early.
     */
    private static function link(string $label, string $href): string
    {
        $target = trim(html_entity_decode($href, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $target = self::replace('~^mailto:~i', '', $target);
        $shown = trim(html_entity_decode(strip_tags($label), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        if ($target === '' || str_starts_with($target, '#') || $target === $shown) {
            return $label;
        }

        $encoded = htmlspecialchars($target, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return $shown === '' ? $encoded : "$label ($encoded)";
    }

    private static function replace(string $pattern, string $replacement, string $subject): string
    {
        return preg_replace($pattern, $replacement, $subject) ?? $subject;
    }
}
