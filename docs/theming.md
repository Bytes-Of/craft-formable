# Theming

Formable's rendered form ships styled. The look is built entirely from
`--formable-*` CSS custom properties on a dedicated cascade layer, so you
restyle a form by **overriding a handful of properties from your own
stylesheet** - no template copies, no `!important`, no specificity fight.

Most sites never need to write any. **[The palette](#the-palette-without-writing-css)**
in Settings → Formable covers a brand colour, the form's surface, text and
border colours, corner radius and button style, with no CSS at all.

This page is the reference: the theme levels, the palette, how overriding
works, a worked rebrand, and every token with its default value.

## Theme levels

The `themeLevel` setting decides how much CSS a rendered form pulls in. Three
levels, from the accessibility contract alone up to the full baseline look:

| Level      | Ships                                                                                                                                                                       |
| ---------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| `default`  | **The default.** The `reset` layer, plus the full visual theme - colour, typography, spacing, radius, every component painted. Override tokens for a branded form.  |
| `reset`    | `contract` plus layout - flex/grid, the disabled/submitting state hooks. No colour, typography or radius. For a site bringing its own design system. |
| `contract` | **The opt-out level.** The accessibility contract alone - `.formable-visually-hidden`, `[hidden]`/`.formable-field--hidden` state, and a tokenless focus-ring shape - plus a small structural normalize (list markers, `<legend>`/`<fieldset>` sizing, file-field icon sizing) that survives your own site's CSS reset. No layout, no paint. For a headless or heavily-Tailwind site that wants none of `reset`'s layout opinions but still needs the markup's a11y contract honoured. An unlayered rule in your own stylesheet still beats the layout and paint this level omits anyway, and beats the structural normalize too if it matches the same (0,2,0) specificity (see "How overriding works" below) - the accessibility contract itself is the one part of this level that's deliberately not up for a plain override. |

`none` (no CSS at all) is a legacy value from pre-release builds - the plugin
still accepts it, but it now behaves exactly like `contract` and isn't offered
in either settings screen. It never fully opted a site out: the markup's
`.formable-visually-hidden` and `[hidden]`/`.formable-field--hidden` rules had
nowhere else to come from, so at `none` a hidden field's label read out loud,
and a Table field's per-cell names ("Email, row 1", "Email, row 2", ...) sat on
the page as visible text.

Branding a form doesn't mean picking a different level - it means staying on
`default` and overriding the tokens it reads. You keep every painted
component and re-point the properties they read. The
[rebrand example](#rebrand-in-six-lines) below is exactly this.

### Setting the level

Globally, in **Settings → Formable** (_Form Stylesheet_), or in
`config/formable.php`:

```php
return [
    'themeLevel' => 'reset',
];
```

Per form, from the builder's Settings tab (Appearance → Theme Level) - the
form keeps its own level regardless of what the site's global setting or any
other form uses:

```php
$form->setSettings(['themeLevel' => 'reset'] + $form->getSettings());
```

Per render, overriding both the global and per-form settings for one call:

```twig
{{ craft.formable.renderForm('contact', { theme: 'reset' }) }}
```

Precedence when more than one applies: the render option wins, then the
form's own `themeLevel`, then the global setting - each one only takes effect
when the one before it is left unset.

`theme: true` and `theme: false` still work as aliases for `'default'` and
`'none'` - the boolean this option was in pre-release builds. An existing
`config/formable.php` with `'includeTheme' => true` is coerced to `'default'`.

Upgrading from a pre-release build and want the old bare-markup look back? Set the level to
`contract` - `none` still works too, but resolves to the same thing.

## How overriding works

Every Formable style sits in one of four cascade layers, declared in this
order (weakest first - layer order is precedence order):

```css
@layer formable.tokens, formable.reset, formable.theme, formable.contract;
```

`formable.contract` is last on purpose. It holds the accessibility rules the
markup depends on - the visually-hidden utility, `[hidden]` handling, the
keyboard focus-ring shape - and those have to win over any layout or paint
declaration Formable makes on the same element, not lose to them. If you write
your own layered CSS against Formable, keep anything that must not break the
form's accessibility out of a layer, or in one you order after
`formable.contract`.

Layered CSS loses to unlayered CSS, regardless of specificity - so a plain
rule in your own stylesheet, including a plain custom-property declaration,
beats anything Formable ships:

```css
/* Your stylesheet, not in any @layer. This wins. */
:root {
  --formable-color-accent-600: #b91c1c;
}
```

Primitives and semantics are declared on `:root` only, and every rule that
paints with one resolves it against whatever's in scope at the element it's
actually painting, not where the token was originally declared - so
overriding one reaches through as far down the page as you scope the
override, with no special-casing needed on Formable's side. Override on
`:root` to reach every surface (the success and closed messages and the
standalone resume page render *outside* `<form>`), on a wrapper of your own
to scope the change to everything inside it, or directly on `.formable-form`
to scope it to one form. The `--formable-*` namespace means a `:root`
override collides with nothing.

**Component tokens reach the same three places, but by a different
mechanism** - each is read with its semantic fallback inline, at the rule
that actually paints with it (`border-color: var(--formable-input-border,
var(--formable-border))`), rather than carrying a default of its own
anywhere. Setting `--formable-input-border` directly still wins outright, on
`:root`, a wrapper, or the form; leaving it unset falls back to whatever
`--formable-border` resolves to at that same element, which is what lets a
`:root` or wrapper override of the **semantic** token reach every component
built on it too.

**One narrower exception stays `:root`-only, by design: a form that's
actually pinned or forced to a colour scheme** (`light`/`dark`, not `auto` -
see [Colour scheme](#colour-scheme) below) has its full semantic palette
restated directly on it, which shadows a `:root`/wrapper rebrand of those
same tokens for that one form - the same "an element's own declaration wins"
rule, applied deliberately so the pin stays authoritative. A form left on the
default resolved scheme with nothing forcing a different one nearby is
unaffected.

**A colour set from the control-panel palette works the same way, and for the
same reason** - it's emitted against the form element, so a `:root` override of
that one token loses to it. Target the form instead; see
[Overriding a colour the palette sets](#overriding-a-colour-the-palette-sets).
This applies only to tokens a palette actually set.

**Two exceptions, both deliberate.** The accessibility contract
(`.formable-visually-hidden` and the `[hidden]`/`.formable-field--hidden`
display rule) carries `!important` inside `formable.contract` - a layered
`!important` outranks *every* unlayered declaration, so this is the one part
of Formable's CSS a plain site rule cannot override at all, on purpose: an
accessibility guarantee your site's CSS can switch off by accident isn't one.
And a small set of structural, colourless rules - list markers on plugin
lists, `<legend>`/`<fieldset>` sizing, control border-width/style, the
primary button's fill, file-field icon sizing - sit outside every layer too,
each written twice as its own class
(`.formable-progress__steps.formable-progress__steps`) for a documented
(0,2,0) specificity. That's what keeps them intact against your *own* site's
unrelated CSS reset (Tailwind Preflight, Bootstrap Reboot, `.prose`), which
would otherwise reach through every Formable layer by the same "unlayered
always wins" rule and strip them by accident. A deliberate override still
wins outright here too - change the `--formable-*` token the rule reads (the
custom property resolves through the normal cascade regardless of layers), or
write your own selector at the same (0,2,0) specificity.

### Three tiers: primitives, semantics and components

The colour system is layered so a rebrand stays small:

- **Primitives** (`--formable-color-accent-600`, `--formable-color-neutral-100`,
  …) are the raw ramps - the only place a literal colour is written.
- **Semantics** (`--formable-border`, `--formable-surface`, …) are a named
  meaning that several components can share - "the neutral border colour",
  "the page-level surface colour".
- **Components** (`--formable-input-border`, `--formable-progress-step-bg`,
  …) are what an individual rule actually paints with. Each one points at a
  semantic token, and each covers exactly one visual role - the input's
  border is its own token, separate from the progress step's border, even
  though both currently point at the same semantic default.

Override a **primitive** and every semantic and component token downstream
follows - this is how six lines restyle the whole form. Override a
**semantic** token to change one broad concept (every neutral border, say)
without touching the ramp. Override a **component** token to retint one thing
- just the inputs, say - without moving anything else that happens to default
to the same value. Do surgery at the component tier for one control, at the
semantic tier for one concept everywhere, and rebrand at the primitive tier.

## The palette, without writing CSS

Before reaching for a stylesheet at all: **Settings → Formable → Appearance**
has six controls that cover the common case.

| Control            | What it moves                                                                  |
| ------------------ | ------------------------------------------------------------------------------ |
| **Brand Colour**   | The primary button's fill, the focus ring, and the progress bar/step indicator |
| **Surface Colour** | The form's own background                                                      |
| **Text Colour**    | Body text                                                                      |
| **Border Colour**  | Input borders                                                                  |
| **Corner Radius**  | Sharp, Soft or Round                                                           |
| **Button Style**   | Filled, Outline or Soft                                                        |

Every one is optional and blank by default. **A form with no palette set
renders exactly as it did before the setting existed** - no extra CSS is
emitted at all, so this costs nothing until you use it.

Set a brand colour and **the text on it is worked out for you**, white or
near-black, from the colour's relative luminance - the button label and the
progress step's number both follow. The screen also shows the contrast ratio
for the pairs WCAG has a threshold for (text against surface at 4.5:1, border
and brand against surface at 3:1) and tells you when one falls short, but it
will not stop you saving: a colour your brand guidelines already settled is not
Formable's to veto.

**Surface, Text and Border assume the form is pinned to a colour scheme.** They
set absolute colours, so they only apply when [Colour scheme](#colour-scheme)
is Light or Dark - which is the default. Set a form to *Follow visitor's
device* and those three are ignored, since the form has to be free to repaint
itself; Brand applies either way.

On **Pro**, a form's own Settings tab carries the same six controls under
**Appearance**, each defaulting to *Use the global setting*. Override the ones
that form needs and leave the rest inheriting - the site keeps one palette, and
a single campaign form can still depart from it.

### Overriding a colour the palette sets

**This is the one place the usual `:root` advice does not apply.** The palette
is emitted against the form element, because a form pinned to a colour scheme
re-declares several of these same tokens on itself and would shadow anything
inherited (the same trap as under [How overriding works](#how-overriding-works)
above).

So to override a colour the palette set, **target the form**:

```css
/* Wins - same element the palette block targets, and unlayered. */
.formable-form,
[data-formable-palette] {
  --formable-button-primary-bg: #6d28d9;
}
```

```css
/* Loses - the form's own declaration beats this inherited one. */
:root {
  --formable-button-primary-bg: #6d28d9;
}
```

A `:root` override still works normally for **every token the palette does not
set**, which is all of them until you set one. The second selector above covers
the success message and the standalone resume page, which render outside
`<form>`.

### `--formable-brand`, from your own stylesheet

If you would rather not use the control panel, the brand seed is a token like
any other, and setting it at `:root` reaches the same three places in one line:

```css
:root {
  --formable-brand: #6d28d9;
}
```

This is the ordinary token path, so `:root` is right here - it only changes
when the CP palette is what set the colour. The two do not need each other, and
the palette wins where both apply.

## Rebrand in six lines

Past the palette, the full token system is still there, and it is what to use
when the six controls do not reach far enough - a different neutral ramp, a
narrower measure, a font stack.

The button is deliberately neutral - near-black on a light page, near-white on
a dark one - so it reads as the plugin's own considered choice rather than an
imported brand colour.
Warm the neutrals, retint the accent that's left - the focus ring and the
progress indicator - and tighten the corners. Nothing else is touched, and
each surface follows because it reads a semantic token that reads these:

```css
:root {
  --formable-color-accent-500: #7c3aed;
  --formable-color-accent-600: #6d28d9;
  --formable-color-neutral-100: #f4f2f8;
  --formable-color-neutral-400: #c9c2d6;
  --formable-radius-md: 0.125rem;
}
```

No selector override, no `!important`. If you find yourself writing either to
get a look, check whether a token already covers it before reaching for the
[escape hatch](#selector-overrides).

Want the button itself in your brand colour too, without moving the neutral
ramp that also carries body text and checked checkboxes? Override its three
semantic tokens directly instead of a primitive - surgery, not rebrand:

```css
:root {
  --formable-button-primary-bg: #6d28d9;
  --formable-button-primary-bg-hover: #5b21b6;
  --formable-button-primary-text: #fff;
}
```

## Token reference

Defaults shown are the shipped values. A `→` means the token is an alias that
points at another token rather than carrying a literal.

### Which tokens work at `reset`

The `reset` layer reads the structural tokens only: `--formable-unit`, the
**spacing scale**, the **rhythm** rungs (`--formable-field-gap` /
`--formable-group-gap` / `--formable-section-gap`, or the `--formable-gap`
alias), `--formable-min-inline-size`, `--formable-form-max-inline-size`, and
`--formable-control-height` / `--formable-control-padding-y`,
`--formable-border-width`, `--formable-focus-ring-width` /
`--formable-focus-ring-offset`, and `--formable-font-size-sm` (for one
caption). Everything else below takes effect only at level `default`.

### Colour - primitives

| Token                          | Default   |
| ------------------------------ | --------- |
| `--formable-color-neutral-50`  | `#fafafa` |
| `--formable-color-neutral-100` | `#f0f0f0` |
| `--formable-color-neutral-200` | `#e0e0e0` |
| `--formable-color-neutral-300` | `#c7c7c7` |
| `--formable-color-neutral-400` | `#a3a3a3` |
| `--formable-color-neutral-500` | `#808080` |
| `--formable-color-neutral-600` | `#636363` |
| `--formable-color-neutral-700` | `#4a4a4a` |
| `--formable-color-neutral-800` | `#333333` |
| `--formable-color-neutral-900` | `#1f1f1f` |
| `--formable-color-neutral-950` | `#121212` |
| `--formable-color-accent-500`  | `#3b82f6` |
| `--formable-color-accent-600`  | `#2563eb` |
| `--formable-color-danger-50`   | `#fef2f2` |
| `--formable-color-danger-500`  | `#ef4444` |
| `--formable-color-danger-600`  | `#dc2626` |
| `--formable-color-danger-700`  | `#b91c1c` |
| `--formable-color-danger-950`  | `#3a1215` |
| `--formable-color-success-50`  | `#f0fdf4` |
| `--formable-color-success-500` | `#4ade80` |
| `--formable-color-success-600` | `#16a34a` |
| `--formable-color-success-700` | `#15803d` |
| `--formable-color-success-950` | `#0f2b1a` |

The neutral ramp is achromatic (equal red/green/blue at every rung) and
monotonic - every rung is lighter than the one below it, all the way from
`-50` to `-950`. `-700`, `-800` and `-950`, and the danger/success `-500` and
`-950` rungs, exist for dark mode - a light rebrand never needs to touch them.

The accent ramp is deliberately small: the button, the checkbox/radio tick
and the secondary/ghost button text all paint from the neutral ramp, so accent
paints only the focus ring and the progress
indicator. A rebrand still overrides it to retint those two; it just no
longer needs a `-700` rung, since nothing reads one.

### Colour - semantics

| Token                                 | Default                        |
| ------------------------------------- | ------------------------------ |
| `--formable-brand`                    | unset - see below              |
| `--formable-surface`                  | `#fff`                         |
| `--formable-surface-raised`           | → `--formable-color-neutral-50` |
| `--formable-text`                     | → `--formable-color-neutral-950` |
| `--formable-text-muted`               | → `--formable-muted`           |
| `--formable-border`                   | → `--formable-color-neutral-500` |
| `--formable-border-hover`             | → `--formable-color-neutral-600` |
| `--formable-border-focus`             | `--formable-brand`, else → `--formable-color-accent-600` |
| `--formable-button-primary-bg`        | `--formable-brand`, else → `--formable-button-bg` |
| `--formable-button-primary-bg-hover`  | → `--formable-color-neutral-950` |
| `--formable-button-primary-text`      | → `--formable-button-text`      |
| `--formable-button-secondary-bg`      | `transparent`                  |
| `--formable-button-secondary-bg-hover`| → `--formable-color-neutral-100` |
| `--formable-button-secondary-border`  | → `--formable-border`           |
| `--formable-button-secondary-text`    | → `--formable-text`             |
| `--formable-button-ghost-bg`          | `transparent`                  |
| `--formable-button-ghost-bg-hover`    | → `--formable-color-neutral-100` |
| `--formable-button-ghost-text`        | → `--formable-text`             |
| `--formable-error`                    | → `--formable-color-danger-700` |
| `--formable-error-surface`            | → `--formable-error-bg`         |
| `--formable-success`                  | → `--formable-color-success-700` |
| `--formable-success-surface`          | → `--formable-success-bg`       |
| `--formable-track`                    | → `--formable-color-neutral-200` |
| `--formable-disabled-bg`              | → `--formable-color-neutral-100` |
| `--formable-progress-fill`            | `--formable-brand`, else → `--formable-color-accent-600` |
| `--formable-progress-text`            | `#fff`                         |
| `--formable-progress-check`           | inline SVG tick (mask)         |

`--formable-brand` is the seed the three rows marked above fall back through -
the button's fill, the focus ring and the progress fill. **It carries no
default on purpose**: with it unset every one of those chains resolves to the
shipped achromatic value, which is what keeps a form neutral until someone
chooses otherwise.
Set it once, at `:root`, and all three follow; or set **Brand Colour** in the
control panel, which does the same thing plus the computed label colours. It's
also what the Outline and Soft button styles paint their label and tinted fill
with.

The `secondary` button is the bordered outline treatment; `ghost` is the same
without the border, for controls that sit inside other content (Edit in the
review list, Remove in a table row). Both read `--formable-text` for their own
colour rather than the accent ramp, so they always match the form's own text.
The progress indicator's travelled part - bar fill, current step circle,
connector behind it - reads `--formable-progress-fill`, one of the two
surfaces (with the focus ring) that still carries the accent colour;
`--formable-progress-text` is the current step number's colour, its own token
because it doesn't follow the button's text the way it used to. Both stay
constant across light and dark - the button inverts per scheme, the progress
indicator doesn't. `--formable-progress-check` is a token because it's painted
as a mask (so it can follow the fill colour); swap it for your own mark
without touching a selector.

### Colour - components

What `formable-theme.css` actually paints with - every rule reads one of
these, never a semantic token directly.
Grouped by the surface they belong to; a `→` still means an alias.

**The input-shaped box** - every control that reads as a text box even when
it isn't literally an `<input>`: the multi-select's combobox and dropdown,
the signature canvas and its typed alternative, the save-and-resume email
field.

| Token                              | Default                     |
| ----------------------------------- | ---------------------------- |
| `--formable-input-bg`              | → `--formable-surface`       |
| `--formable-input-bg-disabled`     | → `--formable-disabled-bg`   |
| `--formable-input-text`            | → `--formable-text`          |
| `--formable-input-text-disabled`   | → `--formable-text-muted`    |
| `--formable-input-placeholder`     | → `--formable-text-muted`    |
| `--formable-input-border`          | → `--formable-border`        |
| `--formable-input-border-hover`    | → `--formable-border-hover`  |
| `--formable-input-border-invalid`  | → `--formable-error`         |
| `--formable-input-accent`          | → `--formable-text`          |

**A selectable row** - a checkbox/radio option, an enhanced multi-select
option, the native multiselect's checked `<option>`.

| Token                          | Default                      |
| ------------------------------- | ----------------------------- |
| `--formable-option-bg-hover`    | → `--formable-surface-raised` |
| `--formable-option-text-disabled` | → `--formable-text-muted`   |

**Select, and the multi-select's own chip/empty states** - the chip row isn't
input-shaped, so it gets its own surface rather than reusing the tokens
above.

| Token                                     | Default                      |
| ------------------------------------------- | ----------------------------- |
| `--formable-select-chevron-color`          | → `--formable-text-muted`     |
| `--formable-multiselect-chip-bg`           | → `--formable-surface-raised` |
| `--formable-multiselect-chip-remove`       | → `--formable-text-muted`     |
| `--formable-multiselect-chip-remove-hover` | → `--formable-text`           |
| `--formable-multiselect-empty-text`        | → `--formable-text-muted`     |

**Field chrome** - the muted caption near a control, not the control itself.
Reused by the signature hint, the file-upload prompt, the save-and-resume
label and the standalone resume page's intro.

| Token                            | Default                  |
| ---------------------------------- | -------------------------- |
| `--formable-field-required-text`  | → `--formable-text-muted`  |
| `--formable-field-hint-text`      | → `--formable-text-muted`  |

**Group / card boundary** - the group wrapper, its no-group divider fallback,
the review step's per-page card, and the `formable-form--bordered` variant
all read one token, since they're deliberately "the same bordered chunk".

| Token                     | Default              |
| --------------------------- | ----------------------- |
| `--formable-group-border` | → `--formable-border`  |

**Message boxes** - the form error summary, the success screen, the
closed-form notice. Each state's own text/background pair, so retinting one
never moves another.

| Token                             | Default                       |
| ------------------------------------ | ------------------------------- |
| `--formable-message-error-text`     | → `--formable-error`            |
| `--formable-message-error-bg`       | → `--formable-error-surface`    |
| `--formable-message-success-text`   | → `--formable-success`          |
| `--formable-message-success-bg`     | → `--formable-success-surface`  |
| `--formable-message-neutral-text`   | → `--formable-text-muted`       |
| `--formable-message-neutral-bg`     | → `--formable-surface-raised`   |
| `--formable-message-neutral-border` | → `--formable-border`           |

**File upload** - the drag-over highlight, the uploaded-file chip and the
rejection notice. The picker button itself already reads the button
component tier below.

| Token                          | Default                       |
| --------------------------------- | -------------------------------- |
| `--formable-file-dragover-border` | → `--formable-border-focus`     |
| `--formable-file-dragover-bg`     | → `--formable-surface-raised`   |
| `--formable-file-chip-bg`         | → `--formable-surface-raised`   |
| `--formable-file-notice-text`     | → `--formable-error`            |

**Progress indicator** - these have their own tokens so that restyling the
step circle doesn't restyle every input on the page, and the other way round.

| Token                                       | Default                   |
| ---------------------------------------------- | ---------------------------- |
| `--formable-progress-label-text`              | → `--formable-text-muted`    |
| `--formable-progress-track-bg`                | → `--formable-track`         |
| `--formable-progress-step-bg`                 | → `--formable-surface`       |
| `--formable-progress-step-border`             | → `--formable-border`        |
| `--formable-progress-step-text`               | → `--formable-text-muted`    |
| `--formable-progress-step-label-text`         | → `--formable-text-muted`    |
| `--formable-progress-step-label-text-current` | → `--formable-text`          |

**Review and signature.**

| Token                                | Default                   |
| --------------------------------------- | ---------------------------- |
| `--formable-review-question-text`      | → `--formable-text-muted`    |
| `--formable-signature-success-border`  | → `--formable-success`       |

**Table** - the scroll-shadow fade has its own token, so it can follow a
table's background without following the form's.

| Token                             | Default                       |
| ------------------------------------ | -------------------------------- |
| `--formable-table-header-text`      | → `--formable-text-muted`       |
| `--formable-table-header-border`    | → `--formable-border`           |
| `--formable-table-row-bg-alt`       | → `--formable-surface-raised`   |
| `--formable-table-scroll-fade`      | → `--formable-surface`          |

`--formable-table-row-bg-alt` and `--formable-option-bg-hover` default to the
same value on purpose - a striped table row and a hovered checkbox/radio row
read as the same "this row" language out of the box - but they're independent
tokens, so retinting one doesn't move the other.

**Buttons** - `--formable-button-secondary-border-hover` (→
`--formable-border-hover`). The other button tokens are listed under
[Colour - semantics](#colour---semantics).

### Focus ring

| Token                          | Default                     |
| ------------------------------ | --------------------------- |
| `--formable-focus-ring-width`  | `2px`                       |
| `--formable-focus-ring-offset` | `1px`                       |
| `--formable-focus-ring-color`  | → `--formable-border-focus` |

The ring has its own colour token so you can retint it without moving the
input's `border-focus` colour, and vice versa.

### The unit

| Token             | Default   |
| ------------------ | --------- |
| `--formable-unit` | `0.25rem` |

Every rung of the spacing scale, the type scale, control height/padding and
the radius scale below is a `calc()` multiple of this one token, chosen so
each rung's default reproduces the exact value it shipped with before
`--formable-unit` existed - overriding it rescales all four at once instead of
each needing its own override.

This is the fix for a host that shrinks its root font size (the `html {
font-size: 62.5% }` trick some sites use to get a `1rem = 10px` base
elsewhere on the page): every `rem`-based Formable token shrinks right along
with it, since nothing in the plugin reads the host's actual pixel size - a
hint or a step label ends up around 8px while the host's own body text,
compensated with an `em`- or pixel-based override the same trick relies on,
does not. Set `--formable-unit` to `0.4rem` on a page like that (undoing a
62.5% root the same way its own body text override does) and every rung
Formable paints with is back to its intended physical size, in one
declaration.

### Spacing

One step per rung of visual hierarchy, tightest gap to space between major
regions, each a multiple of `--formable-unit`.

| Token                | Multiple            | Default    |
| --------------------- | -------------------- | ---------- |
| `--formable-space-1` | `--formable-unit × 1`    | `0.25rem`  |
| `--formable-space-2` | `--formable-unit × 1.5`  | `0.375rem` |
| `--formable-space-3` | `--formable-unit × 2`    | `0.5rem`   |
| `--formable-space-4` | `--formable-unit × 3`    | `0.75rem`  |
| `--formable-space-5` | `--formable-unit × 4`    | `1rem`     |
| `--formable-space-6` | `--formable-unit × 5`    | `1.25rem`  |
| `--formable-space-7` | `--formable-unit × 6`    | `1.5rem`   |
| `--formable-space-8` | `--formable-unit × 7`    | `1.75rem`  |
| `--formable-space-9` | `--formable-unit × 8`    | `2rem`     |
| `--formable-space-10`| `--formable-unit × 10`   | `2.5rem`   |

Steps by a constant unit from `-3` through `-9`, then by two units above that,
rather than the old 8-rung scale's ratios wandering between 1.25x and 1.5x
rung to rung with `1.5rem` and `2rem` skipped entirely. Override an individual
`--formable-space-N` the same as always to move just that rung; override
`--formable-unit` to rescale the whole ladder.

### Rhythm

Three rungs, each read by exactly one level of nesting, so the spacing itself
tells you which fields belong together:

| Token                     | Default                | Governs                              |
| -------------------------- | ----------------------- | ------------------------------------ |
| `--formable-field-gap`     | → `--formable-space-5`  | fields sharing a row                 |
| `--formable-gap`           | → `--formable-space-8`  | pre-1.6 name, back-compat alias feeding `--formable-group-gap` |
| `--formable-group-gap`     | → `--formable-gap`      | rows stacked in a page                |
| `--formable-section-gap`   | → `--formable-space-10` | the form's major regions (error summary, step content, review pages) |

`--formable-gap` used to govern all three levels at once. It's kept as an
alias so an existing override still lands - on row-to-row spacing only, since
a single token was never precise enough to separate the other two. New work
targets the three named rungs.

### Layout

| Token                             | Default  |
| ----------------------------------- | -------- |
| `--formable-min-inline-size`      | `20rem`  |
| `--formable-scroll-margin`        | `0`      |
| `--formable-measure`              | `60ch`   |
| `--formable-form-max-inline-size` | `none`   |

`--formable-min-inline-size` is the floor under `[data-formable-step]`'s
container-query containment (see
[Layout breakpoints](#layout-breakpoints)) - raise it if your own narrowest
supported width is wider than the default, or lower it for a form you know
will only ever sit somewhere narrower.

`--formable-scroll-margin` sits on the step container and on every field's
own controls (`fieldset`, `input`, `select`, `textarea`) as
`scroll-margin-block-start`. It's a no-op until you set it: give it your
sticky header's height so that a multi-page navigation's automatic scroll,
and a click on one of the error summary's field links, both land below the
header instead of behind it.

`--formable-measure` caps how wide a field's instructions and error text get
(`max-inline-size`) before wrapping - a control's own width is the row's
business, but prose left uncapped in a wide layout (a 960px container, a
full-bleed page) reads as an unbroken line with nothing else to stop it.

`--formable-form-max-inline-size` caps the whole form's width, `none` by
default so a form with no opinion keeps filling its container exactly as
before - a block container, a flex column, or a grid track alike. Set it on a
very wide container (a full-bleed page, a 960px+ layout with no column of its
own) so the form doesn't stretch into rows wide enough to read as sparse
rather than dense - `40rem`-`48rem` is a reasonable measure for a form's own
width, wider than `--formable-measure`'s `60ch` prose cap since a row can hold
several fields side by side. The form centres itself (`margin-inline: auto`)
once this is narrower than its container, rather than staying pinned to the
start edge.

### Typography

`font: inherit` stays on every control - the plugin ships no font stack - so
these are size, weight and line-height only. The six sizes are multiples of
`--formable-unit`, same as the spacing scale.

| Token                            | Multiple                 | Default     |
| ---------------------------------- | -------------------------- | ----------- |
| `--formable-font-size-xs`        | `--formable-unit × 3.25` | `0.8125rem` |
| `--formable-font-size-sm`        | `--formable-unit × 3.5`  | `0.875rem`  |
| `--formable-font-size-base`      | `--formable-unit × 4`    | `1rem`      |
| `--formable-font-size-lg`        | `--formable-unit × 4.5`  | `1.125rem`  |
| `--formable-font-size-xl`        | `--formable-unit × 5`    | `1.25rem`   |
| `--formable-font-size-2xl`       | `--formable-unit × 6`    | `1.5rem`    |
| `--formable-font-weight-normal`  |                             | `400`       |
| `--formable-font-weight-medium`  |                             | `500`       |
| `--formable-font-weight-semibold`|                             | `600`       |
| `--formable-line-height-tight`   |                             | `1.3`       |
| `--formable-line-height-normal`  |                             | `1.5`       |

`-2xl` is the step title (`.formable-page__label` and the review heading);
`-lg` is an in-page `Heading` field, kept two rungs below it so the two levels
read as distinct. `-tight` is applied to those headings; `-normal` is the
leading the form sets on label and instruction text, which the theme owns
rather than inheriting because both can wrap.

### Radius and border

`-sm`/`-md`/`-lg` are multiples of `--formable-unit`, same as spacing and
type; `-full` is the pill radius and stays a literal, since it's always meant
to exceed whatever half the element's own size is, not to scale with it.

| Token                     | Multiple                 | Default                |
| --------------------------- | --------------------------- | ---------------------- |
| `--formable-radius-sm`    | `--formable-unit × 1`    | `0.25rem`             |
| `--formable-radius-md`    | `--formable-unit × 1.5`  | `0.375rem`            |
| `--formable-radius-lg`    | `--formable-unit × 2`    | `0.5rem`              |
| `--formable-radius-full`  |                             | `999px`               |
| `--formable-radius`       | → `--formable-radius-md`  |                         |
| `--formable-border-width` |                             | `1px`                 |

### Shadow

The elevation language, kept in one place. `-sm` lifts the primary action in
the button row; both are here for you to paint with.

| Token                  | Default                    |
| ---------------------- | -------------------------- |
| `--formable-shadow-sm` | `0 1px 2px rgb(0 0 0 / 0.06)` |
| `--formable-shadow-md` | `0 4px 12px rgb(0 0 0 / 0.1)` |

### Motion

| Token                       | Default                          |
| --------------------------- | -------------------------------- |
| `--formable-duration-fast`  | `0.15s`                          |
| `--formable-duration-base`  | `0.25s`                          |
| `--formable-ease`           | `cubic-bezier(0.4, 0, 0.2, 1)`   |

Both durations drop to near-zero under `prefers-reduced-motion` - see
[below](#reduced-motion).

### Control density

Also multiples of `--formable-unit`.

| Token                            | Multiple                 | Default    |
| ----------------------------------- | --------------------------- | ---------- |
| `--formable-control-height`      | `--formable-unit × 10`   | `2.5rem`   |
| `--formable-control-padding-x`   | `--formable-unit × 2.5`  | `0.625rem` |
| `--formable-control-padding-y`   | `--formable-unit × 2`    | `0.5rem`   |

A form set to **Compact** in its settings carries `formable-form--compact`,
which redefines the three tokens above (to `--formable-unit × 9` / `× 2` /
`× 1.5` - `2.25rem` / `0.5rem` / `0.375rem`) plus the three rhythm rungs
(`--formable-field-gap` to `--formable-space-4`, `--formable-group-gap` to
`--formable-space-6`, `--formable-section-gap` to `--formable-space-8`).
Nothing else changes - see [Templating → Styling](templating.md#styling).

### Variant classes

Density is one axis of three. All three are plain classes on the `<form>`,
each backed by a `FormSettings` field - **Form Density**, **Label Position**
and **Appearance** in the builder's Settings tab, under Appearance. A fourth
field there, **CSS Class**, adds your own class alongside them - the settings
equivalent of `craft.formable.renderForm(form, {class: '…'})`, for anything a
site-wide stylesheet keys off that the three settings don't cover.

| Class                        | Effect                                                             |
| ----------------------------- | ------------------------------------------------------------------ |
| `formable-form--compact`      | Density - shorter controls, tighter rhythm. See above.             |
| `formable-form--bordered`     | Wraps the whole form in the same bordered-card recipe as `.formable-group` and the review step, reading `--formable-group-border`. |
| `formable-form--labels-left`  | A plain field's label sits beside its control instead of above it, at `--formable-label-width` (default `9rem`); instructions and errors still span the full row. A fieldset-grouped field (Name, Address, Radio, Checkboxes, Table) is unaffected - `<legend>` can't be laid out beside its own fieldset's other children in any current browser, so those keep their legend-above layout. |

Classes combine freely - a compact, bordered, labels-left form carries all
three. None of them touch a selector in `formable-theme.css` beyond their own
- `formable-form--bordered` is one rule, `formable-form--labels-left` is a
handful in `formable-reset.css` (it's a layout change, not a paint one, so it
lives with the rest of the structural CSS) - so a form that never carries the
class renders exactly as before.

### Progress indicator geometry

Also multiples of `--formable-unit`.

| Token                              | Multiple              | Default    |
| ---------------------------------- | ---------------------- | ---------- |
| `--formable-progress-track-height` | `--formable-unit × 2` | `0.5rem`   |
| `--formable-progress-step-size`    | `--formable-unit × 7` | `1.75rem`  |

The stepped indicator's connector-line arithmetic reads
`--formable-progress-step-size`, so the line still meets the circles after you
resize them.

### Back-compat aliases

The five pre-1.1 names some sites already override. Each carries a value and
the matching semantic token reads *through* it, so an existing override still
lands. **New work targets the semantic names** - don't add to this list.

| Token                      | Default                        | Feeds                       |
| -------------------------- | ------------------------------ | --------------------------- |
| `--formable-muted`         | → `--formable-color-neutral-600` | `--formable-text-muted`     |
| `--formable-button-bg`     | → `--formable-color-neutral-900` | `--formable-button-primary-bg` |
| `--formable-button-text`   | `#fff`                         | `--formable-button-primary-text` |
| `--formable-error-bg`      | → `--formable-color-danger-50`   | `--formable-error-surface`   |
| `--formable-success-bg`    | → `--formable-color-success-50`  | `--formable-success-surface` |

## Colour scheme

The `colorScheme` setting decides which palette a rendered form paints with,
emitted as `data-formable-scheme` on the `<form>` (and on the success message
that can render above it):

| Value  | Behaviour |
| ------ | --------- |
| `light` | **The default.** The form always paints light, regardless of the visitor's OS preference or an ancestor forcing dark. A form dropped onto an unknown host page never repaints its own text near-white out from under a dark-OS visitor. |
| `dark`  | The mirror pin, for a form you know sits in a dark section of your own design. |
| `auto`  | Follows `prefers-color-scheme` (or an ancestor forcing one, below) - the behaviour every form had before this setting existed. Because that source is outside the plugin's control, an `auto` form also paints its own background (`--formable-surface`), so it stays legible on whatever page it lands on rather than assuming the host's background moves with it. |

At level `default`, a `@media (prefers-color-scheme: dark)` block repaints
**only the semantic colour tier** under `auto` - structural tokens (spacing,
radius, type, motion) are untouched, because dark mode is a colour opinion,
not a layout one. `color-scheme: light dark` is set on the form so native
chrome (checkbox ticks, the select listbox, scrollbars) follows the same
resolved scheme.

Because only semantics move, your primitive overrides carry into dark mode
automatically. If you need dark-specific values for an `auto` form, add your
own media block - unlayered, so it wins:

```css
@media (prefers-color-scheme: dark) {
  :root {
    --formable-color-accent-500: #a78bfa;
  }
}
```

Dark mode deliberately does **not** repaint the back-compat aliases - it's new
behaviour, not a promise to sites already overriding those names.

### Setting the scheme

Globally, in **Settings → Formable** (_Colour Scheme_), or in
`config/formable.php`:

```php
return [
    'colorScheme' => 'auto',
];
```

Per form, from the builder's Settings tab (Appearance → Colour Scheme):

```php
$form->setSettings(['colorScheme' => 'dark'] + $form->getSettings());
```

Per render, overriding both the global and per-form settings for one call:

```twig
{{ craft.formable.renderForm('contact', { colorScheme: 'dark' }) }}
```

Precedence, same shape as `themeLevel`: the render option wins, then the
form's own `colorScheme`, then the global setting.

### Forcing a scheme from your own site

Independent of the setting above, a site with **its own** light/dark switch
can still drive Formable's palette directly with `data-formable-scheme`, set
to `"light"` or `"dark"`, on any ancestor:

```html
<html data-formable-scheme="dark">
```

An explicit declaration always beats inheritance, so a form resolved to
`light` or `dark` by the setting above ignores an ancestor forcing the other
way - the same rule that lets the form win locally over its own ancestor.
Set the form's `colorScheme` to `auto` to let an ancestor's forced attribute
through instead: `auto` never pins a value of its own, so the ancestor's
`data-formable-scheme` is what the ambient `@media` fallback resolves against.

Put the attribute on `<html>` to reach every `auto` form on the page (and the
success, closed and resume surfaces, which render outside `<form>`), on a
wrapper to reach just the forms inside it, or on the form itself as the
per-render option above already does. This is a supported public contract,
not an internal hook; it stays stable across releases.

`color-scheme` (native chrome - the date picker, the `accent-color` checkbox)
follows a forced scheme the same way.

## Layout breakpoints

Every layout switch responds to the **space the step actually has**, not the
viewport - a 960px-wide page with a 280px sidebar gets the narrow layout in
that sidebar, and a 960px `<dialog>` gets the wide one, regardless of what
else is on the page. This is `@container`, not `@media`: `_step.twig`'s
wrapper (`[data-formable-step]` - the progress indicator, the current page or
review, and the navigation) is a named inline-size container, `formable-step`,
and every rule below queries it.

| Layout                                     | Switches at                        |
| ------------------------------------------- | ----------------------------------- |
| Row columns (`data-formable-row-fields`)   | intrinsic - no breakpoint, see below |
| Row explicit widths (`data-formable-row-widths`) | `inline-size >= 40rem`        |
| Stepper orientation (stacked → side by side) | `inline-size >= 40rem`            |
| Review answer split (label / value columns) | `inline-size >= 40rem`             |
| `formable-form--labels-left`               | `inline-size >= 40rem`              |
| Stepped progress indicator (full list → label alone) | `inline-size < 40rem`, or 8+ steps at any width |
| Table field (columns → stacked cards)      | `inline-size < 40rem`               |
| Form actions (row → stacked, full width)   | `inline-size < 40rem`               |

An equal-split row (every field left at its default `auto` width) needs no
query at all: `grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr))`
fills the row with as many 12rem-or-wider columns as actually fit, so a
3-field row is already one column in a 280px container and three columns the
moment there's room. Only a row where a field sets an explicit width
(`half`/`third`/`quarter`) needs the fraction to mean something, which only
happens once the step is wide enough - that variant, and every other switch
above, stays behind the `40rem` container query.

`formable-form--labels-left` below its threshold isn't a special case: with no
rule matching, a labels-left field just renders as `.formable-field`'s
ordinary stacked layout - label above control, no indent on instructions or
errors - the same as every field that never opted into labels-left at all.

The three narrow-only switches go the other way from everything above -
they're the ones that need *less* room, not more:

- The **stepped progress indicator** collapses its full step list down to the
  label alone (already extended with the current page's name - "Step 2 of 6 ·
  Contact info") below the row threshold, since a long form's list otherwise
  stacks roughly 40px per step and pushes the first field on a phone-width
  page down accordingly. A form with eight or more steps collapses the same
  way at *any* width, since a stepper that long squeezes its labels
  regardless of how much room the container has.
- A **Table field** stops laying its columns out side by side and stacks each
  row as its own card instead - there's no equivalent reflow for a grid of
  columns meant to line up the way `.formable-row`'s intrinsic `auto-fit`
  reflows a row of independent fields. Each card reuses the same per-cell
  label a screen reader already had (normally invisible, since the column
  header said the same thing) rather than repeating the column name as
  separate content.
- The **form actions** (Back, Next/Submit, the save-and-resume block) stack
  full width instead of sharing a row - two roughly 44px touch targets side
  by side get cramped fast in a narrow container, and a phone has the
  vertical room a desktop row doesn't.

### The containment hazard

Making `[data-formable-step]` a container gives it **size containment**: it
reports no intrinsic size of its own to whatever wraps it. A host that sizes
itself to its content - a `<dialog open>` with no explicit width, for
instance - has nothing left to size to, and the form collapses to nothing.
`--formable-min-inline-size` (default `20rem`) is the floor that stops that:
the container never sizes narrower than
`min(var(--formable-min-inline-size), 100%)`, capped by its own containing
block rather than the viewport - a 280px sidebar or a phone with host padding
is narrower than the default floor without being anywhere near the viewport
width, and the floor has to yield to that container instead of forcing a
horizontal scrollbar past it. Inside a `<dialog>` or `[popover]` - a host
that sizes *itself* to its content, so there's no containing-block width for
a percentage to resolve against - the floor falls back to
`min(var(--formable-min-inline-size), 100vw)` instead, the same trade as
before: not tight enough to stop a scrollbar on a viewport narrower than the
default, but a shrink-wrapped dialog is already expected to cap itself
against the viewport. Raise the token if your own narrowest supported width
is wider than `20rem`.

### Containment also pins `position: fixed`

`container-type: inline-size` doesn't only turn on container queries - per
spec it also switches on `contain: layout style inline-size` on the same
element. Layout containment makes `[data-formable-step]` a containing block
for any `position: fixed` descendant, and a new stacking context, the same
way a `position: relative` ancestor, a transform or a filter would. Nothing
Formable ships is affected: the multiselect popup is `position: absolute`
against `.formable-multiselect`, and reCAPTCHA/hCaptcha challenge panels
attach to `<body>`, above the step entirely. But a third-party widget
rendered *inside* a form's own markup that assumes `position: fixed` reaches
the viewport - a portalled date picker, an "expand to full screen" control,
an inline embed - will be positioned and sized against the step instead.
Give a widget like that a portal target outside the form rather than relying
on `position: fixed` from inside one.

## Embedding

Everything above works the same whether a form owns the page or sits inside
someone else's layout - a modal, a sidebar, a `<dialog>`, a card in a grid.
This section is the practical checklist for that case; each point links to
where the mechanism itself is documented.

- **Narrow containers.** Layout responds to the space the form's own step has,
  not the viewport - see [Layout breakpoints](#layout-breakpoints) above. A
  280px sidebar gets the narrow layout regardless of the page it's on. If the
  host sizes itself to its content (a width-to-content `<dialog>`, a
  shrink-wrapped card), read
  [The containment hazard](#the-containment-hazard) - `--formable-min-inline-size`
  is the floor that stops the form collapsing to nothing in one. The same
  containment also repoints `position: fixed` inside a step - see
  [Containment also pins `position: fixed`](#containment-also-pins-position-fixed)
  if you render a fixed-position widget inside a form.
- **Modal and sidebar forms.** No layout token is modal-specific - a modal is
  just a narrow, often short-lived container. The part that *is*
  modal-specific is the JavaScript lifecycle: a form injected after the page's
  own load isn't enhanced on its own, and closing a modal on a successful
  submit needs the right event. Both are covered in
  [Templating → JavaScript lifecycle](templating.md#javascript-lifecycle) -
  `Formable.init(root)` for the first, the `formable:success` event for the
  second.
- **AJAX-loaded forms.** The same JavaScript lifecycle section covers Sprig
  and htmx wiring - re-running `Formable.init()` on their own swap events -
  and the reminder that whatever renders the partial still has to call
  `registerAssets` (or leave the default `true`), so the script and
  stylesheet arrive with the first swap, not only on the page's initial load.
- **CSP.** The rendered pack writes no inline `style` attribute, so it works
  under a strict `style-src` unmodified - see
  [Templating → Content Security Policy](templating.md#content-security-policy)
  for what `script-src` needs and how the few values that can't be expressed
  as a class (the progress fill, a textarea's rows, a Table field's column
  widths) are set instead.
- **A form's place in your page's own heading outline.** `renderForm` and
  `renderStep` accept a `headingLevel` option (default `2`) - the page label,
  the review heading and the success heading all start from it, with the
  review's per-page sub-headings one level below. Set it so a form embedded
  under, say, an `<h3>` in your own template doesn't jump back up to `<h2>`:

  ```twig
  {{ craft.formable.renderForm('contact', { headingLevel: 4 }) }}
  ```

  See [Templating → Rendering](templating.md#rendering) for the full options
  table.

### Placing `@layer formable` among host layers

Formable's four stylesheets each declare their content inside
`@layer formable.tokens`, `formable.reset`, `formable.theme` and
`formable.contract` - see [How overriding works](#how-overriding-works). Those
are sub-layers of one outer `formable` layer, and like any CSS layer, **where
`formable` sits relative to a host's own layers is decided by the order layer
names are first seen across the whole page, not by which stylesheet loads
first or which one has more specific selectors.**

A host design system that also uses layers - Tailwind v4 (`@layer theme, base,
components, utilities;`), Open Props, or a hand-rolled one - is doing the same
thing Formable is. If neither side says anything about the other, the layer
that's *named first* anywhere in the document ends up weakest, same as inside
Formable's own four. In practice that means:

- **Formable's stylesheet linked before the host's compiled CSS** (the common
  case: `{% do craft.formable.registerAssets() %}` runs early, the site's own
  bundle loads after in the document `<head>`) → `formable` is named first, so
  it's the *weaker* stack. A host `utilities` layer rule beats a Formable
  `theme` rule even at equal specificity - which is usually what you want: the
  site's own design system should be able to reach into an embedded form
  without a specificity fight.
- **To reverse that** for one page - Formable should win over a host layer
  it would otherwise lose to - declare the order yourself, before either
  stylesheet loads, with an empty `@layer` statement:

  ```css
  /* In a stylesheet that loads before both Formable's and the host's. */
  @layer host, formable;
  ```

  An empty `@layer` statement only fixes relative order - it declares no
  rules of its own, so it's inert bytes if you never end up using it, and
  matches Formable's real inner layer (`formable`, the parent of `formable.tokens`
  etc.) rather than needing to name all four.
- **Either way, `formable.contract`'s accessibility rules are unaffected.**
  They carry `!important` inside their layer specifically so layer order
  can't relegate them below a host rule - see the two exceptions in
  [How overriding works](#how-overriding-works). Layer placement only decides
  who wins on *paint and layout*, never on the a11y contract.

If you're not deliberately reordering anything, the default (Formable named
first, so a host's own layered CSS wins ties against it) is the one to keep -
it's what makes "your rule wins" true without you having to think about layer
order at all, and reordering is the escape hatch for the rarer case where you
want it the other way for one embed.

## Right-to-left (RTL)

Every physical direction in `formable-reset.css` and `formable-theme.css` -
margins, padding, the select chevron, the stepper connector, the error list's
indent and bullets, table cell alignment - is written as a logical property
(`margin-inline-start`, `padding-inline-end`, `inset-inline-start`,
`text-align: start`, and so on) rather than `left`/`right`. Nothing in the
plugin's own CSS has to change for an Arabic or Hebrew site: set `dir="rtl"`
on the `<form>` or an ancestor and the whole layout mirrors, stepper
connectors included - a connector positioned with `inset-inline-start` grows
toward whichever side is "forward" under the current `dir`, so it always
reaches the next step's circle without a direction-specific rule.

`stylelint-use-logical` enforces this on every source file under
`src/web/assets/frontend/css/` (`.stylelintrc.json`'s `csstools/use-logical`
override) - a `margin-left` or `text-align: left` in a later change fails
`npm run lint:css` rather than quietly reintroducing an LTR assumption. Sizing
properties (`width`/`height` and their `min`/`max` variants) are exempted: the
plugin only targets horizontal writing modes, so `inline-size`/`block-size`
would be churn with no correctness benefit here.

The save-and-resume standalone page (`_resume.twig`) sets `dir` from
`craft.app.locale.orientation`, the same source Craft's own control panel
uses, alongside the `lang` it already set. An embedded form has no page of its
own to set `dir` on - it inherits whatever the host page declares, which is
the correct default for a form dropped into someone else's layout.

Free-text inputs (`Text`, `Email`, `Url`, `Textarea`, and the `Name`/`Address`
sub-fields) carry `dir="auto"`, so a single answer aligns to whatever script
the visitor actually typed - an English name in an Arabic-language form, or a
line of Arabic in an English one - independently of the form's own direction.

## Reduced motion

A `@media (prefers-reduced-motion: reduce)` block drops
`--formable-duration-fast` and `--formable-duration-base` to `0.01ms`. Every
transition in the theme reads one of those two tokens, so this silences every
animated state change without a `transition: none` rule anywhere. It's
near-zero rather than `0s` so a `transitionend` listener still fires.

## Selector overrides

The escape hatch, for the rare thing no token covers. Because the theme is
layered, an unlayered rule in your stylesheet wins with no `!important`:

```css
.formable-button--submit {
  text-transform: uppercase;
  letter-spacing: 0.05em;
}
```

The rendered markup carries stable, prefixed class names and `data-*`
attributes for exactly this. See
[Templating → Styling](templating.md#styling) for the class and attribute
surface, and [Templating → Overriding templates](templating.md#overriding-templates)
if you need to change the markup itself.

### Classes with no paint of their own

Some `formable-*` classes the markup carries are targeting hooks, not styled
rules - the theme paints the base element and leaves these for your stylesheet
to reach:

| Class | What paints instead |
| --- | --- |
| `.formable-button--submit`, `--next`, `--edit`, `--save` | `.formable-button` + `--primary` / `--secondary`; the modifier names the button's role |
| `.formable-field--text` (and every other `--{type}`), `.formable-input--text` / `--number` / `--phone` / `--relation` | the shared `.formable-input` look; the modifier identifies the field type |
| `.formable-field--error`, `.formable-field--required` | the invalid control's border and the message below it (error); the `*` marker (required) |
| `.formable-progress--none` / `--bar` / `--steps` | the inner `.formable-progress__*` elements; the modifier flags which indicator is in use |
| `.formable-multiselect__chip-label` | `.formable-multiselect__chip`; the label span is split out so you can style it without the remove button |
| `.formable-file` | nothing until JavaScript upgrades it to `.formable-file--enhanced` |
| `.formable-save__email` | `.formable-input`, so the resume-email field matches the controls beside it |

The `FrontendHooksTest` unit test holds this list: a `formable-*` class the
markup emits must be painted by one of the theme sheets or recorded there as a
deliberate hook, so a class the design system forgets to style fails CI rather
than shipping invisible.
