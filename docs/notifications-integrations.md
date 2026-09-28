# Notifications, integrations & captchas

## Notifications

**Notifications are available in every edition.** A form can have any number of
email notifications, each firing when a submission is accepted.

Set them up in the form builder's **Notifications** panel. Each notification has:

| Setting                    | Notes                                                                   |
| -------------------------- | ----------------------------------------------------------------------- |
| **Name**                   | Internal label.                                                         |
| **Enabled**                | Toggle without deleting.                                                |
| **Recipients / CC / BCC**  | Who receives it.                                                        |
| **Reply-To**               | Reply address.                                                          |
| **From name / From email** | The sender shown to recipients.                                         |
| **Subject**                | Twig-renderable (see below).                                            |
| **Body**                   | Twig-renderable; falls back to a default table of all submitted values. |
| **Attach files**           | Attach the submission's uploaded files to the email.                    |
| **Conditions** _(Pro)_     | Only send when the submission matches a rule.                           |

### Templating a notification

Subject and body are rendered against the submission, so you can interpolate
submitted values by field handle:

```twig
New enquiry from {{ name }}

Email: {{ email }}
Message:
{{ message }}
```

Leave the body empty and Formable sends a default table of every submitted
field.

**A submitted value in the body is HTML-encoded automatically.** `{{ name }}`
prints whatever the submitter typed as plain text, even if they typed
`<script>` or a stray `<div>` - it can never open a tag inside your email. Your
own markup around it is untouched: `<p>Thanks, {{ name }}!</p>` still sends a
paragraph, with `{{ name }}` swapped in safely. This applies to `{token}`,
`{{ name }}` and `{{ values.name }}` alike, and to the **From name** and **From
email** fields. It does not extend to writing Twig directly against
`submission` or `form` (`{{ submission.getValue('name') }}`,
`{{ form.title }}`) - avoid that in a body if the value could contain markup a
submitter controls. There is no way to opt back out of the encoding: every
field in Formable holds something a submitter typed, and none of them is meant
to carry trusted HTML.

### Email template

Every notification is sent inside an **email template** - by default a plain,
centred layout with inline styles, built to survive the email clients that strip
everything else. The body you write in the builder is the content inside it, so
your branding lives in one file instead of being pasted into every
notification's body.

To use your own, create a template in your site's `templates/` folder. Formable
uses the first of these it finds:

1. `<Custom Template Path>/email/notification.twig` - for one form. It is the
   same **Custom Template Path** that form's front-end overrides use (see
   [Overriding templates](templating.md#overriding-templates)), so
   `_forms/contact` means `templates/_forms/contact/email/notification.twig`.
2. `formable/email/notification.twig` - for every form on the site.
3. Formable's own, which is the best starting point for yours: copy
   `vendor/bytesof/craft-formable/src/templates/email/notification.twig`.

Your template receives these variables:

| Variable                                    | Holds                                                                                                               |
| ------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `body`                                      | The notification's rendered body, or the table of every field if the body is blank. It is already HTML: `{{ body }}` |
| `subject`                                   | The rendered subject.                                                                                               |
| `notification`                              | The notification itself, e.g. `notification.name` - handy for one template that styles an autoresponder differently. |
| `submission`, `form`, `values`, `allFields` | The same variables the body has.                                                                                    |

A body you wrote as a complete HTML document - starting with `<!DOCTYPE html>`
or `<html>` - is sent exactly as written, without the template.

If your template has an error, Formable logs it and sends the body on its own
rather than not sending the notification at all. Check a new template with
**Send test** before relying on it.

### Plain-text version

Every notification carries a plain-text version alongside the HTML, generated
from the final email. Text-only email clients show it, and spam filters mark
down mail that doesn't have one. Line breaks follow your markup, links keep
their address in brackets after the link text, and the default table of fields
reads as one `Label: value` line per field. There is nothing to set up.

### Conditional notifications (Pro)

Add **conditions** to a notification and it only sends when the submission
matches - e.g. only email the sales team when _Budget_ is over a threshold. In
**Lite**, conditions are ignored and every enabled notification simply sends.

### Weekly digest (Pro)

Instead of (or alongside) per-submission emails, Formable can send a **weekly
digest** summarising recent submissions. Enable it in **Settings → Formable**.
It's driven by a console command you schedule with cron:

```bash
php craft formable/digest
```

In Lite the digest run is a no-op.

## Integrations (Pro)

Integrations forward each accepted submission to a third-party service. Configure
them in the form builder's **Integrations** panel, mapping form fields to the
service's fields.

| Integration   | What it does                                                          |
| ------------- | --------------------------------------------------------------------- |
| **Slack**     | Posts a submission summary to a channel via an Incoming Webhook URL.  |
| **Mailchimp** | Subscribes the submitter to a Mailchimp audience (email-marketing).   |
| **HubSpot**   | Creates a HubSpot contact from the submission (CRM).                  |
| **Webhook**   | POSTs (or PUTs) the whole submission as JSON to a URL of your choice. |

Provider credentials (API keys, webhook URLs) live in the plugin settings and
accept `$VAR` env references like every other Craft secret. They are **never**
included in form exports (see [import-export.md](import-export.md)).

**Formable → Integrations**, where these instances are created and
configured, needs the **Manage integrations** permission - it does not require
an admin account. See [Permissions](permissions.md).

### What a webhook sends

The request body is JSON:

```json
{
  "form": { "id": 12, "handle": "grantApplication", "title": "Community Grants Programme 2026" },
  "submission": { "id": 4821, "dateCreated": "2026-09-24T10:14:00+01:00" },
  "data": { "orgName": "Ashcombe Angling Club", "grantTier": "Major - £10,001 to £25,000" },
  "raw": { "orgName": "Ashcombe Angling Club", "grantTier": "major" }
}
```

`data` holds each answer as the text a person would read. A Dropdown, Radio
Buttons, Checkboxes or Multi-select answer is the option's **label**, the same
text the submission, the CSV export and notifications show. `raw` holds the
same answers as they're stored, so an option answer is its **value**. Branch
on `raw` when the receiver makes decisions: an author can reword a label at any
time, but the value stays put. Every other integration and notification uses
the labels.

### Where integrations may send

An integration only calls **public** addresses. A URL whose host resolves to a
private, loopback or link-local address (`10.x`, `192.168.x`, `127.0.0.1`,
`169.254.169.254` and the like) is refused before anything is sent, and the
delivery log says why. This is what stops someone with the **Manage
integrations** permission from aiming your server at your internal network. It
also means redirects are not followed: a webhook that answers with a 3xx fails
and needs its URL updated to the final address.

If a receiver really does live on your internal network, an admin can allow it
under **Formable → Settings → Integrations → Integration Allowed Hosts**, or in
`config/formable.php`:

```php
return [
    'integrationAllowedHosts' => "hooks.internal.example\n10.0.4.0/24",
];
```

One entry per line: a hostname (allowed whatever it resolves to), an IP address
or a CIDR range (allows only addresses inside it).

### Forms that don't store submissions

An integration delivers a saved submission - the queued job, its retries and the
delivery log all point at one - so it can't run on a form with **Store
Submissions** turned off. Notifications can: a notify-only form still emails
every submission, it just keeps no copy in the control panel.

The builder makes the limit visible rather than leaving an integration to
quietly do nothing. With storage off, the **Integrations** panel shows a notice
and won't let you switch an integration on, and saving is refused while one is
still on, with the error under **Store Submissions** naming which. Switch those
integrations off, or turn storage back on. A form saved this way before the
check existed hits the same error the next time it's saved.

### Lite behaviour

Integrations are visible and configurable in Lite so a Lite admin can _see_ and
set up the feature - but a submission is **never forwarded** until you upgrade to
Pro. The panel shows an upgrade prompt: _configure now, activates on upgrade._
Nothing errors and no configuration is lost.

Add your own integration type through `Integrations::EVENT_REGISTER_INTEGRATION_TYPES`

- see [extending.md](extending.md).

## Resending a failed delivery

Every attempt to send a notification or forward a submission to an integration
is logged. The form builder shows the most recent ones under **Recent sends** on
the Notifications tab and **Recent deliveries** on the Integrations tab, each
with the time of the attempt linked to its submission.

A failed attempt is retried automatically for a few minutes - a notification up
to five times through Craft's queue, an integration up to five times with a
growing delay. After that Formable stops. If the row is still **Failed**, its
**Resend** button sends it again:

- **It tries once, straight away**, tells you whether it worked, and adds the
  attempt to the log. If it fails again, the new row carries the button.
- **It uses the current setup**, not the one that failed. Fix the cause first -
  a mistyped recipient, a broken Twig subject, an expired API key, a field
  mapping - then resend.
- **Only the latest attempt for a submission can be resent**, so a delivery an
  automatic retry has already recovered is never sent twice. A resend can't see
  a retry that is still waiting to run, though: if the failure is only a
  minute or two old, let the automatic retries finish before you resend.
- **It isn't offered** when the notification or the submission has been deleted,
  when the submission has since been marked as spam, or - for integrations -
  when the integration is disabled or this form no longer sends to it.
- **A notification you've since disabled still resends.** Enabled decides what a
  new submission triggers; pressing Resend is an explicit request for this one.
  Conditions aren't re-checked for the same reason.

Resending needs the same permission as editing the form (_Create and edit
forms_). Resending to an integration needs **Pro**: in Lite nothing is forwarded,
so there is nothing to resend.

## Captchas (Pro)

Captchas add a bot challenge to a form, verified server-side on submit. Formable
supports:

- **reCAPTCHA v2**
- **reCAPTCHA v3** (with a configurable score threshold, default `0.5`)
- **hCaptcha**
- **Cloudflare Turnstile**

Enter each provider's **site key** and **secret key** in **Settings → Formable**
(both accept `$VAR` env references), then choose the captcha per form in the
form's settings. A captcha renders and verifies only once both keys are present.

In **Lite**, the captcha picker shows an upgrade prompt and no challenge is
rendered or verified. Honeypot and JS spam checks remain available in both
editions.
