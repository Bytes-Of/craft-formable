# Templating

Formable ships a complete front-end **template pack** - the markup for a whole
form, down to each field type. You can render a form with one call and never
touch the markup, or override any part of it to match your site.

## Rendering

The `craft.formable` Twig variable is the front-end entry point.

```twig
{# Render a whole form by handle #}
{{ craft.formable.renderForm('contact') }}

{# Render one field, when you're laying out markup by hand #}
{{ craft.formable.renderField('contact', 'email') }}

{# Register the JS/CSS bundle without rendering (for hand-built markup) #}
{% do craft.formable.registerAssets('contact') %}
```

`renderForm`, `renderField` and `renderFormShell` (below) all accept an options array:

| Option           | Default                            | Effect                                          |
| ---------------- | ---------------------------------- | ----------------------------------------------- |
| `templatePath`   | form's own setting, else the pack | Directory to resolve templates from (see below) |
| `theme`          | global _Form Stylesheet_ setting  | Theme level to load - see [Theming](theming.md) |
| `registerAssets` | `true`                            | Register the front-end JS/CSS bundle            |
| `id`             | unique per render (`renderForm` only) | The `<form>` element's id. See [Accessibility](#accessibility) below if you render the same form more than once on a page. |
| `class`          | none                               | An extra class (or space-separated list) added to the `<form>` element, alongside the form's own presentation classes. |
| `headingLevel`   | `2`                                | The heading level (`1`-`6`) the page label, review and success headings render at. Set it to match where the form sits in your page's own heading outline - see [Embedding](theming.md#embedding). |

### Laying out fields by hand

`renderField()` on its own gives you a field's markup, but nothing around it -
no `<form>` tag, CSRF input, error summary or captcha. `renderFormShell()`
gives you those, so you can lay fields out in your own markup and keep every
guarantee `renderForm()` gives, without reconstructing the surrounding form
yourself:

```twig
{% set shell = craft.formable.renderFormShell('contact') %}

{{ shell.open }}
    <div class="my-grid">
        {{ craft.formable.renderField('contact', 'firstName') }}
        {{ craft.formable.renderField('contact', 'email') }}
    </div>
{{ shell.close }}
```

`shell.open` renders the success notice, the `<form>` tag with every attribute
`renderForm()` would give it, the CSRF / action / handle / flow / `pageIndex`
inputs, the honeypot and the error summary. `shell.close` renders the captcha,
the Submit button and `</form>`.

Write the template exactly as shown, with **no `{% if %}` around the block**.
A closed form doesn't need one: `shell.open` still prints the closed notice,
and wraps your fields through to `shell.close` in a `<div hidden>` instead of
requiring you to branch around them yourself, so the naive template is already
correct on a shut form. `shell.accepting` is exposed if you want to branch
anyway - to skip rendering the fields at all, say - and `shell.formId` for
your own CSS or JS.

## The template pack

The pack lives in [src/templates/frontend/](../src/templates/frontend/). The
top-level partials assemble a form:

| Template                        | Renders                                                        |
| ------------------------------- | -------------------------------------------------------------- |
| `form.twig`                     | The `<form>` wrapper and page container                        |
| `_shellOpen.twig` / `_shellClose.twig` | The same `<form>` wrapper, split so `renderFormShell()` can wrap it around hand-laid-out fields instead of `form.twig`'s own page container |
| `_page.twig`                    | A single page of fields                                        |
| `_row.twig`                     | A row of fields within a page                                  |
| `_field.twig`                   | The label / instructions / error wrapper around a field        |
| `_nav.twig`                     | Next / Back / Submit (and the save-and-resume button, Pro)     |
| `_progress.twig` / `_step.twig` | The multi-page progress indicator                              |
| `_review.twig`                  | The optional review-before-submit page                         |
| `_captcha.twig`                 | The captcha widget (Pro)                                       |
| `_spam.twig`                    | The honeypot / JS spam fields                                  |
| `_resume.twig`                  | The save-and-resume prompt (Pro)                               |
| `_closed.twig`                  | Shown in place of the form when it isn't accepting submissions |

Per-field markup lives under
[src/templates/frontend/fields/](../src/templates/frontend/fields/) - one
template per field type (`text.twig`, `email.twig`, `checkboxes.twig`,
`signature.twig`, …), plus `_subFields.twig` for composite fields (Name,
Address) and `relation.twig` for relation fields (Entries, Categories, Users,
Assets).

## Overriding templates

You don't copy the whole pack to change one thing. Each `include` in the pack
resolves through `craft.formable.template(name, templatePath)`, which looks for
the template in your override directory first and **falls back to the plugin's
pack per-template**. Override one field template and the rest keep coming from
Formable.

There are two ways to point at an override directory:

1. **Per form** - set the form's **Template Override Path** in its settings. Its
   value is a directory _inside your site's own `templates/` folder_.
2. **Per render** - pass `templatePath`:

   ```twig
   {{ craft.formable.renderForm('contact', { templatePath: '_forms/contact' }) }}
   ```

To override, say, the text field: create
`templates/_forms/contact/fields/text.twig` in your site. Formable will use it
for text fields on that form and keep using its own pack for everything else.

Inside an override template you can re-resolve nested includes so _they_ also
fall back independently:

```twig
{{ include(craft.formable.template('_row', templatePath), { row: row }) }}
```

A form's override directory can also hold its notification email template, at
`email/notification.twig` - see
[Email template](notifications-integrations.md#email-template).

An override can print `field.label`, `field.instructions` and `option.label`
directly - a site's translation overrides are already applied before the field
reaches your template, so there's no separate helper to route through. See
[Translations](translations.md#how-resolution-works).

## Styling

Formable ships a **stylesheet**, on by default, built entirely from
overridable `--formable-*` custom properties. The theme levels (`default` /
`reset` / `contract`), the full token reference, dark mode, and a worked
"rebrand in six lines" example are all in **[Theming](theming.md)**.

The rendered markup also carries stable, prefixed class names and `data-*`
attributes, so you can style forms entirely from your own stylesheet without
touching templates.

A field's **Width** setting surfaces here: a sized field carries
`formable-field--width-half` (or `--full` / `--third` / `--quarter`) and its row
carries `data-formable-row-widths`. A row of all-`auto` fields carries neither
and lays out exactly as it did before the setting existed.

A form set to **Compact** in its settings carries `formable-form--compact` on
the `<form>`. The bundled stylesheet reads it to tighten the control height,
control padding and the gap between fields; nothing else changes, and a
`Comfortable` form carries no extra class. At level `none` the class is still
emitted but styles nothing.

## Conditional logic in templates (Pro)

Conditional logic is applied by the front-end runtime - fields and pages show
and hide as the submitter types. If you build markup by hand, expose the
condition data the runtime reads:

```twig
{% set conditions = craft.formable.conditionsData(page.settings.conditions ?? null) %}
<div data-formable-conditions="{{ conditions|json_encode }}"> … </div>
```

Each field also exposes its own condition config through
`field.getConditionsConfig()`. In **Lite**, conditional logic is disabled: every
field and page resolves visible, and this data is absent - forms render in full.

## Member self-service submissions (Pro)

A logged-in member can be let edit or delete their own completed submissions
from the front end - a member's account page listing the forms they've filled
in, say, with a link to update or withdraw one. Formable has no built-in page
for this; it gives you the query and the two endpoints, and you build the
template.

```twig
{% for submission in craft.formable.submissions().form('contact').all() %}
    <a href="/account/submissions/{{ submission.id }}">{{ submission.dateCreated|date }}</a>
{% endfor %}
```

`craft.formable.submissions()` is always scoped to the signed-in visitor - it
forces `userId` onto whatever criteria you pass, so a template can filter
within a member's own submissions but never widen past them, and a guest gets
a query that matches nothing rather than an error. `craft.formable.submission(id)`
resolves one by id, or `null` if it isn't the visitor's - the guard a "view /
edit my submission" page loads through, before it ever renders a form for it.

The two actions themselves take the same posted shape as a normal submit:

- **`formable/submissions/update-own`** - `submissionId` plus `fields[…]`
  (and a page's file inputs, for a multi-page form's upload fields). Re-runs
  validation and the form's conditional logic, so an answer behind a
  now-hidden field doesn't survive the edit.
- **`formable/submissions/delete-own`** - `submissionId` alone.

Both require `POST`, a logged-in visitor, and Pro; either one 404s outright
in Lite. On Pro, each form additionally needs its own **Member Self-Service**
setting turned on (**Settings → Privacy & data**, off by default) - without
it, both actions respond exactly as if the submission didn't exist, so a site
that never built a self-service page for a form isn't quietly editable by a
direct POST to it regardless. A form that has opted in still refuses once it
stops accepting submissions (disabled, or outside its Pro schedule window),
even for the submission's own owner. Ownership is checked against the
submission's stored `userId`, never against whatever id a request posts, so a
member can only ever reach a row that's actually theirs.

## Inline validation

A handful of declarative rules - **required**, **type** (email, URL, number),
**length** and **min/max** - are checked in the browser as the submitter types
and again, authoritatively, on the server. This is a preview only: the
server-side check (`FormField::validateValue()`) is what actually decides
whether a submission is accepted, and a submitter with JavaScript off never
sees the preview at all - the form still validates and reports errors through
the normal page-reload path, exactly as it always has.

It covers Single-line Text, Multi-line Text, Email Address, Website, Phone
Number and Number - the fields with one plain control and a value shape simple
enough to check declaratively. Everything else (choices, files, a signature, a
consent checkbox, composite fields like Name and Address) keeps validating
server-side only. Available in both editions - it isn't gated, unlike
conditional logic.

If you build a field's markup by hand rather than through `_field.twig` -
overriding a field's whole partial, not just its input template - you take on
everything that partial normally gives a field for free:

- **The `-instructions` / `-errors` id convention.** `_field.twig` derives
  `{inputId}-instructions` and `{inputId}-errors` from the control's own id
  and lists whichever of them apply in the control's `aria-describedby`.
  `field-errors.ts` - the code that adds and removes a field's error markup at
  runtime, shared by the AJAX submit path and the inline-validation pass -
  rebuilds the `-errors` id from the control's id the same way and patches it
  into `aria-describedby` itself. Give the control a different id shape, or
  don't wire `aria-describedby` up to `-instructions`/`-errors` at all, and a
  screen reader stops hearing the instructions and errors it's sitting right
  next to.
- **The `#{formId}-field-{field.id}` link targets the summary relies on.** The
  server-rendered error summary (`form.twig`) links to each failing field with
  a hardcoded `href="#{{ formId }}-field-{{ field.id }}"` - `_field.twig`'s
  own default id shape, not something read back off your markup (it falls
  back to the bare `formable-{field.id}` when `renderField()` is called
  outside a form shell, which has no `formId`). Give the control or its
  `<fieldset>` a different id and that link lands nowhere. The AJAX-built
  summary is more forgiving - `buildErrorSummaryItem()` in `main.ts` finds the
  field by `data-formable-field` and links to whatever id its `fieldset,
  input, select, textarea` actually has - but the two paths then disagree on
  where a page-reload visitor and a JS visitor land, which is its own defect
  worth avoiding.
- **The `data-formable-*` hooks the enhancers need.** `data-formable-field="{{
  field.handle }}"` on the field wrapper is how every enhancer, the
  conditional-logic engine and the AJAX error path find a field at all -
  drop it and none of them see the field. Add `data-formable-field-errors`
  on the error container so `field-errors.ts` can find and clear it, plus
  `data-formable-validation` and `data-formable-conditions` (see above and
  [Conditional logic](#conditional-logic-in-templates-pro)) if the field
  opts into either. A field type with its own front-end enhancer - Multi-select,
  Signature, Table, File Upload - needs that field's own hook too
  (`data-formable-multiselect`, `data-formable-signature`,
  `data-formable-table`, `data-formable-file-upload`); check that field's
  shipped input template for the exact shape before overriding it.

For validation specifically, expose the same data the runtime reads:

```twig
{% set validation = field.getValidationSpecConfig() %}
<div data-formable-field="{{ field.handle }}"
    {%- if validation %} data-formable-validation="{{ validation|json_encode }}"{% endif %}> … </div>
```

A field with nothing to check (optional, no type, no length or bounds)
returns `null` and emits no attribute at all - the same "absent means
unconstrained" shape conditions use. The `messages` key inside the spec is
already localized, ready-to-show text keyed by which rule failed
(`required`, `type`, `minLength`, `maxLength`, `min`, `max`); the front end
looks a message up, it never assembles one itself.

A validation failure the client already caught is reported through the same
error summary a server-rejected submission uses, reading its message from the
form's `data-formable-error-message` attribute - the form's own configured
**Error Message** setting, so both paths read identically.

## Caching forms

Every rendered form - single-page or multi-page - includes a `csrfInput()`
token, and that token is minted _when the markup is rendered_. If the page
holding the form is cached - Craft's `{% cache %}` tag,
[Blitz](https://putyourlightson.com/plugins/blitz), a reverse proxy or a CDN -
the one token baked into the cached HTML is served to every visitor. Craft
validates the token against the visitor's own session, so everyone except
whoever triggered the cache write gets a CSRF failure and a 400 on submit.
This applies to single-page forms exactly as it does to multi-page ones, and
it's the more severe problem: a multi-page form degrades under caching, a
form with a stale CSRF token stops accepting submissions outright.

Multi-page forms have a second, independent issue. They keep the submitter's
place with a per-render **flow id** - a hidden `formableFlow` value the shell
mints on first render and posts back on every step. It is what stops two tabs
open on the same form from interleaving their answers into one session slot.
That id is baked into cached markup the same way the CSRF token is. Progress
stays isolated _between_ visitors (the session slots behind the id are
per-visitor), but a single visitor who opens the form in two tabs is served
the same baked id in both, so the per-tab isolation degrades to the
interleaving it exists to prevent.

**Recommendation:** don't cache the markup of a form, or the page region that
holds it. When you cache a template that renders one, keep the form outside
the cached block:

```twig
{% cache %}
    {# expensive page chrome #}
{% endcache %}

{{ craft.formable.renderForm('signup') }}
```

Full-page caching (Blitz, a CDN) of a URL that carries a form has the same
effect - add the URL to the cache's exclusion list, or use one of the two
options below to keep the CSRF token itself cache-safe:

- **Craft's `asyncCsrfInputs` general config setting.** With it on, `csrfInput()`
  emits a placeholder instead of a baked-in token, and Craft's own front-end
  JavaScript fetches a fresh one after the page loads. This makes the page
  itself cacheable, but it still doesn't solve the multi-page flow-id problem
  above, so a multi-page form still needs to sit outside the cached region or
  have its URL excluded.
- **Blitz's own CSRF injection**, `craft.blitz.csrfInput()`, used in place of
  Craft's `csrfInput()`. Blitz fetches and injects a fresh token via a small
  AJAX request when the page is served from cache. This only helps for pages
  Blitz itself is caching - it isn't a substitute for `asyncCsrfInputs` under
  a reverse proxy or CDN that Blitz doesn't control.

Formable doesn't call either of these on your behalf, because doing so is a
site-wide caching decision, not a per-form one. If you override
`_shellOpen.twig` to swap in one of them, remember it still needs to run for
every form the override renders, not just the happy path.

## Content Security Policy

The rendered pack works under a strict `style-src` - no `'unsafe-inline'`
needed. Nothing in the template pack writes a `style` attribute; the handful
of values a server-rendered class can't express - the multi-page progress
bar's fill width, a textarea's row-based height, a Table field's
author-configured column widths - are set from `main.ts` through the CSSOM
(`element.style.foo = …`), which a `style-src` policy doesn't govern the way it
governs an inline `style` attribute or a `<style>` element. `script-src` needs
whatever already serves `FrontendAsset`'s bundle (`'self'`, or your CDN/hash),
same as any other first-party script.

Without JavaScript at all - CSP unrelated, just disabled - each of those three
degrades to something merely imprecise, never wrong: the progress bar's fill
stays at its CSS default while the textual "Step 2 of 5" label beside it
carries the real progress; the textarea and Table field columns fall back to
the browser's own native sizing.

**A brand palette is the one thing Formable renders as a `<style>` element**,
which a strict `style-src` does govern. It's emitted that way on purpose: the
palette is one static declaration set rather than a per-element value, so
applying it from JavaScript alone would paint the shipped near-black button on
every page load and flip it after hydration - a visible flash, traded for a
policy most sites don't run.

So a form carrying a palette does both. The `<style>` block is primary, and the
runtime then checks whether it actually applied, re-applying the same
declarations through the CSSOM if the policy dropped it. With a normal policy
that's one computed-style read and no writes; under a strict one the brand
still arrives, a beat later. With **both** a strict policy and JavaScript off,
the form falls back to the shipped palette - imprecise, not broken, the same
standard as the three values above. A form with no palette set emits no
`<style>` element at all, so it needs nothing from `style-src` either way.

## JavaScript lifecycle

`FrontendAsset`'s bundle enhances every `<form data-formable-handle>` already
on the page at `DOMContentLoaded` (or immediately, if the script runs after
that - it's loaded deferred). A form that arrives later - a Sprig or htmx
partial, a form injected into a modal, anything rendered after that first pass
- isn't picked up on its own. Call the documented entry point once its markup
is in the document:

```js
// Default root is `document` - use it after inserting markup anywhere on the
// page and you're not sure where.
Formable.init();

// Scoped to a subtree - skips re-scanning forms already enhanced elsewhere on
// the page, which matters if you're doing this on every swap of a busy page.
Formable.init(document.getElementById('my-modal'));
```

`Formable.init(root?)` is idempotent - a form already carrying
`data-formable-enhanced` is skipped, so calling it again after another
library's own re-render is harmless. `FormableForm`, the class itself, is also
on `window` for lower-level access (`FormableForm.forHandle('contact')`
resolves and enhances one form by its handle).

**Sprig**, whose swaps replace a target element's contents over AJAX, re-runs
your own JS through
[`sprig:init`](https://putyourlightson.com/plugins/sprig#events):

```js
document.addEventListener('sprig:init', (event) => {
  Formable.init(event.target);
});
```

**htmx** fires
[`htmx:afterSwap`](https://htmx.org/events/#htmx:afterSwap) with the swapped
element as `event.detail.target`:

```js
document.addEventListener('htmx:afterSwap', (event) => {
  Formable.init(event.detail.target);
});
```

Either way, make sure the response that carries the form also registers the
bundle - `{% do craft.formable.registerAssets('contact') %}` in the partial
template, or `registerAssets: true` (the default) on whatever renders the
form - so the script and stylesheet are present the first time a form is
swapped in, not only on the page's initial load.

A form's whole markup is replaceable at runtime - a modal that swaps its
content, a wizard step someone routes through their own JS - and it dispatches
four `CustomEvent`s on itself, `bubbles: true`, so a listener anywhere upstream
(commonly `document`) can react without editing the template pack:

| Event              | Fires when                                              | `event.detail` |
| ------------------- | -------------------------------------------------------- | -------------- |
| `formable:success`  | The submission completed - the success message rendered, or a redirect is about to happen | the response object (`message`, `successDetails`, `restartLabel`, `redirect`, `submissionId`, …) |
| `formable:error`    | Validation failed, or the request itself failed (network error) | the response object, or `{ success: false }` for a network failure |
| `formable:saved`    | Save-and-resume stored the submitter's progress (Pro)   | the response object (`saved: true`, `message`) |
| `formable:closed`   | The form shut between page load and submit (disabled, or past its open/close window) | the response object (`closed: true`, `message`) |

Moving between pages of a multi-page form is not one of these - it's not a
terminal outcome, so no event fires for it. Listen for `formable:success` to
close a modal or fire an analytics call once a submission actually completes:

```js
document.addEventListener('formable:success', (event) => {
  myModal.close();
  analytics.track('form_submitted', { submissionId: event.detail.submissionId });
});
```

Without JavaScript, or on the plain page-reload submit method, none of this
fires - the four outcomes above are what the server-rendered page itself
already shows.

## Accessibility

The pack is accessibility-first: labels are associated with their controls,
errors are announced, required state is conveyed to assistive tech, and the
multi-page flow is keyboard navigable. If you override templates, preserve the
`aria-*` attributes and label associations to keep that guarantee.

Every id `renderForm` mints - the `<form>` itself, each page, each field - is
namespaced to that particular render, so calling `renderForm` twice for the
same form on one page (two embeds, or a form included from two different
places) never produces duplicate ids, and each render's error-summary links,
labels and `aria-describedby` references stay pointed at its own controls. Pass
an explicit `id` option if you want a stable, predictable `<form>` id instead -
in that case, keep it unique yourself if you embed the same form more than
once on a page.

`renderField`, used inside a [form shell](#laying-out-fields-by-hand) or on
its own to lay out a field's markup by hand, still ids the field from its own
id alone (`formable-42`) and isn't namespaced this way. That's deliberate: it's
what lets `renderFormShell()`'s error summary link straight to
`#formable-{field.id}` with no `formId` threaded through by the author, at the
cost of keeping it to at most one shell, or one bare `renderField()` render of
a given field, per page.

A required field the shell's author doesn't render still validates on submit -
Formable validates every field on the form, not just the ones a particular
template happens to print - but its error then has nowhere to display: the
page-reload summary links to an id `renderField()` never emitted, and the AJAX
summary (`buildErrorSummaryItem()` in `main.ts`) silently drops any handle it
can't find a `[data-formable-field]` wrapper for. Render every required field
somewhere in the shell.
