<p align="center">
  <img src="docs/assets/logo.svg" alt="Formable" width="420">
</p>

# Formable - Form Builder for Craft CMS 5

Formable is a commercial form builder for Craft CMS 5: a drag-and-drop builder,
~26 field types, multi-page forms, conditional logic, notifications, third-party
integrations, spam protection, and an accessibility-first front-end template
pack. It ships in two editions - **Lite** and **Pro**.

## Requirements

- Craft CMS 5.10.7+
- PHP 8.2+ (8.3 recommended)

## Installation

Install from the **Plugin Store** in the Craft control panel, or with Composer:

```bash
composer require bytesof/craft-formable
php craft plugin/install formable
```

## Quick example

Build a form in the control panel (**Formable → Forms → New form**), give it a
handle, then render it anywhere in a Twig template:

```twig
{{ craft.formable.renderForm('contact') }}
```

That single call renders the whole form - every page, field, the progress bar,
captcha, and the submit button - with markup you can fully theme or override.

## Editions

|                                                   | Lite | Pro |
| ------------------------------------------------- | ---- | --- |
| Form builder, ~26 fields, multi-page forms        | ✅   | ✅  |
| Notifications                                     | ✅   | ✅  |
| Front-end rendering + accessibility pack          | ✅   | ✅  |
| Honeypot & JS spam checks                         | ✅   | ✅  |
| Conditional logic                                 | -    | ✅  |
| Captchas (reCAPTCHA, hCaptcha, Turnstile)         | -    | ✅  |
| Integrations (Slack, Mailchimp, HubSpot, Webhook) | -    | ✅  |
| Save & resume                                     | -    | ✅  |
| Scheduled forms & submission limits               | -    | ✅  |
| Data-retention purge & weekly digest              | -    | ✅  |
| GraphQL API                                       | -    | ✅  |

Every Pro feature degrades gracefully in Lite - an upgrade prompt or a quiet
no-op, never a dead control or a raw error. See [docs/editions.md](docs/editions.md)
for the full gate-by-gate breakdown.

## Documentation

- [Getting started](docs/getting-started.md) - install, editions, your first form
- [Templating](docs/templating.md) - the front-end pack, overrides, theme CSS
- [Fields](docs/fields.md) - every field type and its settings
- [Notifications & integrations](docs/notifications-integrations.md) - emails, Slack, Mailchimp, HubSpot, webhooks, captchas
- [Headless & GraphQL](docs/headless-graphql.md) - the GraphQL query and mutation surface
- [Extending](docs/extending.md) - custom fields, custom integrations, events
- [GDPR & data protection](docs/gdpr.md) - retention, encryption, IP/consent options
- [Import & export](docs/import-export.md) - the `formable/forms` console commands
- [Editions](docs/editions.md) - the Pro/Lite feature gate audit

## License

Proprietary - commercial plugin for the Craft Plugin Store.
