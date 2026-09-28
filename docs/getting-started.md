# Getting started

## Requirements

- Craft CMS 5.0+
- PHP 8.2+ (8.3 recommended)

## Install

From the **Plugin Store** in the Craft control panel, search for _Formable_ and
install. Or with Composer:

```bash
composer require bytesof/craft-formable
php craft plugin/install formable
```

Once installed, a **Formable** section appears in the control-panel navigation.

## Editions

Formable ships in two editions:

- **Lite** - the form builder, ~24 field types, multi-page forms,
  notifications, the front-end template pack, and honeypot/JS spam checks.
- **Pro** - everything in Lite plus conditional logic, captchas, integrations
  (Slack, Mailchimp, HubSpot, Webhook), save & resume, scheduled forms and
  submission limits, data-retention purge, the weekly digest, and the GraphQL
  API.

You can build and use a form in Lite and upgrade later - every Pro feature shows
an upgrade prompt in Lite rather than a dead control, and no data is lost when
you switch. See [editions.md](editions.md) for exactly how each feature behaves
per edition, and set the active edition from **Settings → Plugins → Formable**
(or `php craft plugin/edition formable pro`).

## Build your first form

1. Go to **Formable → Forms → New form**.
2. Give the form a **name** and a **handle** (the handle is how you reference it
   in templates - e.g. `contact`).
3. Drag fields from the field palette onto the canvas. Fields are grouped into
   **rows** and **pages**; add pages for a multi-step form.
4. Configure each field in the settings drawer (label, handle, required,
   instructions, and field-specific options - see [fields.md](fields.md)).
5. Set up **notifications** so you get an email when someone submits (see
   [notifications-integrations.md](notifications-integrations.md)).
6. Save.

## Render the form

Render a form anywhere in a Twig template by its handle:

```twig
{{ craft.formable.renderForm('contact') }}
```

This outputs the complete form - all pages, fields, the progress indicator,
captcha (Pro), and buttons - using Formable's front-end template pack. The
runtime handles multi-page navigation, conditional logic (Pro), and AJAX
submission automatically.

You can also pass a `Form` element (handy when you already have one in scope):

```twig
{% set form = craft.formable.form('contact') %}
{{ craft.formable.renderForm(form) }}
```

## Make it look like your site

A rendered form ships styled, in a deliberately neutral grey-and-near-black
palette, so it doesn't import a colour your site never asked for. To put your
own colour on it, go to **Settings → Formable → Appearance**:

1. Set **Brand Colour**. The primary button, the focus ring and the multi-page
   progress bar all pick it up, and the button's label colour is worked out
   from it automatically - white or near-black, whichever is readable.
2. Optionally set **Surface**, **Text** and **Border** colours, and pick a
   **Corner Radius** (Sharp, Soft, Round) and **Button Style** (Filled,
   Outline, Soft).
3. Save, and reload a page with a form on it.

Every field is blank by default, and a form with nothing set renders exactly as
it does today - so this costs nothing until you use it. The screen shows you
the contrast ratio for each pair that has a WCAG threshold, and warns when one
falls short, but it won't block the save.

On **Pro**, any single form can depart from that: its Settings tab has the same
six controls under **Appearance**, each starting on *Use the global setting*.

Anything the six controls don't reach - a different neutral ramp, fonts,
spacing - is a CSS custom property away in [Theming](theming.md).

### Options

`renderForm` accepts an options array:

```twig
{{ craft.formable.renderForm('contact', {
    templatePath: '_forms/contact',   {# override the template pack per render #}
    theme: true,                      {# include Formable's optional theme CSS #}
    registerAssets: true,             {# register the front-end JS/CSS bundle #}
}) }}
```

If a form is disabled, trashed, or outside its schedule window (Pro), it renders
its **closed message** in place of the fields - so you can embed a form
unconditionally and it will do the right thing once it closes.

## Where submissions go

Submissions are stored as elements under **Formable → Submissions**, where you
can review, edit, filter, and export them (CSV/JSON). You can also disable
storage per form if you only want notifications.

The index's search box looks inside the answers, not just the submission title,
so searching `acme.test` finds the submission whichever field the address was
typed into. Encrypted fields are the exception: their values stay out of the
search index by design.

Submissions stored before you upgraded to this version were only indexed by
title. Rebuild the index for them once:

```sh
php craft formable/submissions/reindex             # every form
php craft formable/submissions/reindex --form=contact
```

### Submission statuses

A status is where a submission sits in your workflow. A new install starts with
four - **New**, **In Review**, **Approved** and **Rejected** - and you can change
them under **Formable → Statuses**: add your own, rename them, give them any of
Craft's status colours, drag them into the order you want to see them in, and
delete the ones you don't use.

Statuses are install-wide, not per-form. Each form chooses which of them
incoming submissions start in, on its **Settings** tab; leave that unset and the
default status is used.

They are stored in your **project config**, so statuses you set up on a
development site deploy to staging and production with the rest of your
configuration - which is also why the screen is unavailable wherever
`allowAdminChanges` is off, for admins and delegated non-admins alike. See
[Permissions](permissions.md) for who can reach it otherwise.

Two statuses can't be deleted: the default, and the last one left. Deleting any
other moves the submissions currently sitting in it to the default, so no
submission is ever left without a status.

### Submissions that reference an entry

Answers given through an **Entries**, **Categories**, **Users**, **Assets** or
**File Upload** field point at real Craft elements, and Formable records which
- so the question can be asked from the other end:

```twig
{# Pro: the signed-in member's own submissions about this entry #}
{% set mine = craft.formable.submissions.relatedToElement(entry).all() %}
```

```php
// Every submission that references it, from a module or plugin
Submission::find()->relatedToElement($entry)->all();
```

Both accept an element, an ID, or a list of either. Craft's own `relatedTo`
cannot answer this: Formable's fields are not Craft fields, so nothing about a
form ever reaches Craft's relations table.

If the entry someone selected is later deleted, the submission keeps the answer
it was given - it is a record of what was submitted, not a live pointer. A
selection in the recycle bin still shows its title; one that has been purged
shows as `Deleted element 42` rather than disappearing, so "chose something now
gone" never reads as "chose nothing".

## Rate limiting

Two anonymous actions are capped per IP address, per form, so a script can't
turn either into an unbounded loop: **saving a form for later** (Pro) and
**submitting one** (which also covers a multi-page form's last **Next**, since
that completes the submission too). Each has its own limit and window, in
seconds, under **Settings → Formable → Spam protection** - **Save for Later
Limit** and **Submission Limit**, both on by default and both switched off by
setting the cap to `0`. The submission cap defaults higher than save's,
since a busy shared connection - an office, a kiosk - submitting a form
repeatedly is far more plausible than the same address parking a form to
finish later.

A request past either cap is answered exactly as a successful one - the
submitter isn't told anything was throttled, and nothing distinguishes the
response from a genuine save or submit. Because addresses are frequently
shared (a NAT'd office, a mobile carrier, a proxy), a real visitor behind a
busy connection can occasionally be caught by a low cap; the default is set
high enough that this should be rare; raise it further if your traffic pattern
needs it. Every time either limit turns a request away, a warning is written
to `storage/logs` (category `formable`) naming the form and the limit that
fired, so an unusually high rate of silent throttling is still visible to
whoever reads the logs, even though the submitter never sees it.

A **File Upload** field's uploads are capped the same way, against the
submission numbers specifically, checked once a request is confirmed to carry
a file. Past that cap the request completes or fails validation exactly as it
otherwise would - only the file itself is silently dropped, so a script can't
be used to fill a volume even if it stays under the submission cap.

## Multi-site

**Forms are global.** One form is available on every site, with one layout and
one set of settings - there is no per-site enablement and no per-site label
translation.

**Submissions are attributed.** Each one records the site it was made from, and
on a multi-site install that shows up as a **Submitted from** column on the
submissions index, a row on the submission detail page, a column in the export,
and a per-site breakdown in the weekly digest (Pro). Templates and GraphQL can
filter on it:

```twig
{% set fromDe = craft.formable.submissions.submittedSite('de').all() %}
```

Submissions stored before you upgraded to this version report no site - there
was nothing recorded to report, and Formable will not guess.

## Next steps

- [Templating](templating.md) - override the markup, render fields individually.
- [Theming](theming.md) - theme levels, the `--formable-*` token reference, a
  worked rebrand.
- [Fields](fields.md) - the full field reference.
- [Notifications & integrations](notifications-integrations.md) - wire up emails
  and third-party services.
- [Headless & GraphQL](headless-graphql.md) - query and submit forms over
  GraphQL.
- [GDPR](gdpr.md) - retention, encryption, and consent controls.
