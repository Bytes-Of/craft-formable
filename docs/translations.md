# Translations (Pro)

On a multi-site install, a form's layout and settings are still global - one
form, rendered identically on every site. Translations let you override the
*strings* an author typed, per site, without duplicating the form. Everything
else about the form - its fields, their order, conditional logic, validation,
styling - stays the one shared layout, so a change to the form reaches every
site at once and no site's copy can drift out of step.

## What's translatable

- Every **page's label**.
- A field's **label** and **instructions**, and whichever of its own strings
  are author-facing prose rather than configuration: a **placeholder** (Text,
  Number, Phone, Dropdown), an Agree field's **description**, a Table's **Add
  Row Label** and each **column's label**, a composite field's (Name,
  Address) **sub-field labels**, a relation field's (Entries, Categories)
  **selection label**, a Heading's text, and an HTML block's content.
- Each option's **label**, on Dropdown, Radio Buttons, Checkboxes and
  Multi-select fields.
- Nine settings strings: **Success Message**, **Success Details**, **Error
  Message**, **Closed Message**, **Submit Button Label**, **Next Button
  Label**, **Back Button Label**, **Save and Resume Label**, and **Review
  Page Heading**.

The Translations tab only ever offers an input for what a given field type
actually prints - a Section divider or a Hidden field, for instance, has
nothing here because neither renders a label a submitter can read.

## What isn't

- The layout itself - which fields exist, their order, conditional logic,
  validation rules, and per-form appearance settings.
- Whether the form is enabled, or anything else in its settings besides the
  nine strings above. In particular, **Redirect URL** doesn't appear here -
  it's a URL, not prose meant for a reader.
- Per-site enablement. A form is available on every site or none; there's no
  way to publish it to only some sites.

## Editing translations

Open a form in the builder and switch to the **Translations** tab, alongside
Notifications, Integrations and Settings. Pick a site from the selector at
the top, then fill in overrides for whichever pages, fields or messages need
one. Leaving an input blank means "use the base value" - it's not the same as
typing the base value itself, and clearing an override later reverts to the
base string rather than to whatever the override used to say.

On Lite, the tab is visible but shows an upgrade prompt instead of the
editing panel - your form's base strings render on every site regardless, and
nothing here is a dead control or an error.

The author preview (the **Preview** button in the builder toolbar) carries
your unsaved translations too, and on a multi-site Pro install offers a site
selector of its own so you can check an override before saving.

## How resolution works

A site's overrides are applied to the form's layout **before** it's turned
into the field objects every render path (and the builder's own field map)
uses - not resolved string-by-string as each template prints one. That means:

1. Not Pro? The layout renders untouched. No lookup happens at all - a
   translation saved before a downgrade is preserved on the form, just unread
   until you upgrade again.
2. Otherwise, the current site's overrides (page labels, then each field's
   own translatable keys) are overlaid onto the layout first. A blank or
   missing override leaves the base value in place.
3. The resulting layout is what every render path - the field templates, the
   navigation buttons, the progress indicator, the review page, server-side
   validation messages, and the success/error screens after a submission -
   actually renders from, and what an override template sees too. Printing
   `field.label`, `field.instructions`, `option.label`, or a settings
   property like `settings.submitButtonLabel` directly is correct and
   automatically translated - there's no separate helper method to route
   through, and none exists any more.

The current site is whichever site the request is being served for -
`Craft::$app->getSites()->getCurrentSite()` - the same site Craft resolves
any other localized content against. There's no per-render option to ask for
a different site's strings on the live front end; the author preview's site
selector is a builder-only convenience that switches the current site for
that one preview request.

## Import and export

An exported form carries its translations, keyed by site UID rather than
site ID so they survive moving between installs whose sites aren't numbered
the same way. See [Import & export](import-export.md#whats-in-an-export).

## Notifications and GraphQL

Notification emails and the GraphQL schema's own form and field types both
read the base, untranslated layout - a `label` field in a GraphQL query, or a
token in a notification template, always returns the author's original
string, regardless of which site a submission came from. The one exception is
the `saveFormableSubmission` mutation's closed-form error, which reuses the
same `getClosedMessage()` every other closed-form response goes through and
so resolves for the submitting site like anywhere else.
