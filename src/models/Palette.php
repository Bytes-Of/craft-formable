<?php

declare(strict_types=1);

namespace bytesof\formable\models;

use bytesof\formable\helpers\Color;
use craft\base\Model;

/**
 * A brand palette - four colour seeds plus two presentation knobs, shared
 * between the global default ({@see Settings::$palette}) and a per-form
 * override ({@see FormSettings}). `Rendering::resolvePalette()` merges the
 * two with {@see mergeOver()} before either is turned into CSS.
 *
 * An empty string is "unset" for every colour, not a real value - that's what
 * lets a per-form override leave a colour to the global default, and what
 * lets a fresh install emit nothing at all. See design rule 1
 * (0053, 0089): the author picks one seed, shades are derived, and
 * nothing here does colour maths beyond that.
 *
 * @internal
 */
final class Palette extends Model
{
    public const RADIUS_SHARP = 'sharp';
    public const RADIUS_SOFT = 'soft';
    public const RADIUS_ROUND = 'round';

    public const BUTTON_STYLE_FILLED = 'filled';
    public const BUTTON_STYLE_OUTLINE = 'outline';
    public const BUTTON_STYLE_SOFT = 'soft';

    /**
     * The one seed an author picks. Feeds `--formable-brand`, which
     * `--formable-button-primary-bg`, `--formable-border-focus` and
     * `--formable-progress-fill` already fall back to - see
     * internal/decisions/0091's "brand seed" section.
     */
    public string $brand = '';

    /**
     * Design rule 4: these three assume a pinned colour scheme. They still
     * validate and merge the same as `brand`; suppressing them under `auto`
     * is a presentation-layer concern (the builder's `when` clause), not this
     * model's.
     */
    public string $surface = '';

    public string $text = '';

    public string $border = '';

    /**
     * Read as CSS classes rather than custom properties
     * ({@see toCssDeclarations()}), so unlike the colours these carry a
     * concrete default on a resolved palette. A *merge input* may still leave
     * them unset - see {@see FormSettings::PALETTE_RADIUS_INHERIT}.
     */
    public string $radius = self::RADIUS_SOFT;

    public string $buttonStyle = self::BUTTON_STYLE_FILLED;

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [
            ['brand', 'surface', 'text', 'border'],
            'match',
            'pattern' => '/^#[0-9a-f]{6}$/i',
            'skipOnEmpty' => true,
        ];
        $rules[] = [['radius'], 'in', 'range' => [self::RADIUS_SHARP, self::RADIUS_SOFT, self::RADIUS_ROUND]];
        $rules[] = [
            ['buttonStyle'],
            'in',
            'range' => [self::BUTTON_STYLE_FILLED, self::BUTTON_STYLE_OUTLINE, self::BUTTON_STYLE_SOFT],
        ];

        return $rules;
    }

    /**
     * Layers this palette over a base one, colour by colour - an unset ('')
     * colour here falls through to `$base`'s, a set one wins. Used to resolve
     * a per-form override against the global default
     * ({@see \bytesof\formable\services\Rendering::resolvePalette()}), mirroring
     * `resolveColorScheme()`'s render option -> form -> global precedence.
     *
     * `radius` and `buttonStyle` fall through the same way, which is what
     * keeps the global value reachable from a form that never set its own -
     * see internal/decisions/0090.
     */
    public function mergeOver(self $base): self
    {
        $merged = new self();
        $merged->brand = $this->brand !== '' ? $this->brand : $base->brand;
        $merged->surface = $this->surface !== '' ? $this->surface : $base->surface;
        $merged->text = $this->text !== '' ? $this->text : $base->text;
        $merged->border = $this->border !== '' ? $this->border : $base->border;
        $merged->radius = $this->radius !== '' ? $this->radius : $base->radius;
        $merged->buttonStyle = $this->buttonStyle !== '' ? $this->buttonStyle : $base->buttonStyle;

        return $merged;
    }

    /**
     * Whether this palette sets any colour at all. Used to short-circuit CSS
     * emission for the default, no-brand case - the common one, and the one
     * that has to stay byte-identical to a Formable install with no palette
     * feature at all.
     */
    public function isEmpty(): bool
    {
        return $this->brand === '' && $this->surface === '' && $this->text === '' && $this->border === '';
    }

    /**
     * The custom-property declarations this palette emits, keyed by bare
     * property name (`--formable-brand`, not the full `.formable-form { }`
     * block - that's the caller's job, once per resolved hash).
     *
     * `radius` and `buttonStyle` aren't here: they're read via CSS classes
     * (`.formable-form--radius-sharp`, `--buttons-outline`), the same shape
     * `density`/`labelPosition`/`appearance` already use, not custom
     * properties. `buttonStyle` is still *read* here - see below.
     *
     * **The brand seed is emitted alongside the semantic tokens it feeds, not
     * instead of them** (0091). `--formable-brand` alone would be inert here:
     * `formable-tokens.css` declares `--formable-button-primary-bg` and
     * friends as `var(--formable-brand, …)` at `:root`, and a custom property
     * substitutes its `var()`s at the element it is *declared* on, so a
     * `:root` declaration can never see a brand set on the form. This block
     * lands on the form, so it declares the resolved tokens outright - the
     * same reasoning 0073 applied one tier down.
     *
     * @return array<string, string>
     */
    public function toCssDeclarations(): array
    {
        $declarations = [];

        if ($this->brand !== '') {
            $declarations['--formable-brand'] = $this->brand;
            $declarations['--formable-border-focus'] = $this->brand;
            // `--formable-focus-ring-color: var(--formable-border-focus)` is
            // itself a `:root` declaration, so setting the line above does
            // not reach the ring. It needs saying here too.
            $declarations['--formable-focus-ring-color'] = $this->brand;
            $declarations['--formable-progress-fill'] = $this->brand;
            $declarations['--formable-progress-text'] = Color::readableTextOn($this->brand);

            // The outline and soft variants own all three button tokens and
            // already read the seed themselves, on this same element - a flat
            // brand-coloured label, a tinted fill. Declaring a solid fill here
            // would paint straight over them.
            if (!in_array($this->buttonStyle, [self::BUTTON_STYLE_OUTLINE, self::BUTTON_STYLE_SOFT], true)) {
                $declarations['--formable-button-primary-bg'] = $this->brand;
                $declarations['--formable-button-primary-bg-hover'] = sprintf(
                    'color-mix(in oklab, %s 85%%, black)',
                    $this->brand,
                );
                $declarations['--formable-button-primary-text'] = Color::readableTextOn($this->brand);
            }
        }

        if ($this->surface !== '') {
            $declarations['--formable-surface'] = $this->surface;
        }

        if ($this->text !== '') {
            $declarations['--formable-text'] = $this->text;
        }

        if ($this->border !== '') {
            $declarations['--formable-border'] = $this->border;
        }

        return $declarations;
    }
}
