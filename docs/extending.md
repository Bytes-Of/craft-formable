# Extending Formable

Formable is built to be extended from your own module or plugin - add field
types, add integrations, and hook the submission lifecycle. Register handlers
from your plugin/module's `init()`.

What you can build on without pinning an exact version - and what you
shouldn't touch - is set out in [API stability](api-stability.md). In short:
the base classes, events, elements and Twig variable this page describes are
stable, and everything else is internal.

## Events

Formable exposes an eight-event extensibility surface: two registration events
and six submission-lifecycle events.

### Registration events

| Event                      | Class · constant                                 | Purpose                                |
| -------------------------- | ------------------------------------------------ | -------------------------------------- |
| Register field types       | `Fields::EVENT_REGISTER_FIELD_TYPES`             | Add custom field types to the palette. |
| Register integration types | `Integrations::EVENT_REGISTER_INTEGRATION_TYPES` | Add custom integrations.               |

> There's also `Presets::EVENT_REGISTER_PRESETS` for shipping ready-made form
> templates - same shape as the two above.

### Submission-lifecycle events

All live on the `Submissions` service.

| Constant                       | Fires                                               | Cancelable                                |
| ------------------------------ | --------------------------------------------------- | ----------------------------------------- |
| `EVENT_BEFORE_SUBMIT`          | Before a submission is processed                    | ✅ (`$event->isValid = false` rejects it) |
| `EVENT_AFTER_SUBMIT`           | After a submission is accepted                      | -                                         |
| `EVENT_BEFORE_SAVE_SUBMISSION` | Before a submission element is saved                | ✅                                        |
| `EVENT_AFTER_SAVE_SUBMISSION`  | After a submission element is saved                 | -                                         |
| `EVENT_SUBMISSION_ERROR`       | When a submission is rejected (validation, spam, …) | -                                         |
| `EVENT_AFTER_SAVE_INCOMPLETE`  | After a save-and-resume partial is stored (Pro)     | -                                         |

The lifecycle events carry a
[`SubmissionEvent`](../src/events/SubmissionEvent.php) (`submission`, `isNew`,
and `isValid` on the cancelable ones); `EVENT_SUBMISSION_ERROR` carries a
[`SubmissionErrorEvent`](../src/events/SubmissionErrorEvent.php) (`submission`,
`reason`).

> **Note:** Formable guards every lifecycle `trigger()` with
> `hasEventHandlers()`, so on the hot submit path nothing is constructed unless
> a handler is actually attached.

### Example - reject a submission conditionally

```php
use bytesof\formable\services\Submissions;
use bytesof\formable\events\SubmissionEvent;
use yii\base\Event;

Event::on(
    Submissions::class,
    Submissions::EVENT_BEFORE_SUBMIT,
    function (SubmissionEvent $event) {
        if (str_contains((string) $event->submission->getValue('email'), '@blocked.test')) {
            $event->isValid = false; // reject
        }
    },
);
```

## Custom field types

Register the class, then implement it by extending the appropriate base in
[src/base/](../src/base/):

```php
use craft\events\RegisterComponentTypesEvent;
use bytesof\formable\services\Fields;
use yii\base\Event;

Event::on(
    Fields::class,
    Fields::EVENT_REGISTER_FIELD_TYPES,
    function (RegisterComponentTypesEvent $event) {
        $event->types[] = \mymodule\fields\Rating::class;
    },
);
```

Pick a base class:

| Base                 | Use for                                                |
| -------------------- | ------------------------------------------------------ |
| `FormField`          | Any single-value field.                                |
| `OptionsFormField`   | Choice fields with an options list (dropdown, radio…). |
| `RelationFormField`  | Fields relating the submission to Craft elements.      |
| `CompositeFormField` | Fields made of sub-fields (Name, Address).             |
| `CosmeticFormField`  | Presentational, value-less fields (heading, divider).  |

A field defines its settings schema (for the builder drawer), how it
normalizes/serializes its value, and its front-end template. Follow a built-in
field in [src/fields/](../src/fields/) as a template.

If your field's value is a *reference* to Craft elements rather than data of its
own, implement `getRelatedElementIds()` and return them in selection order.
Submissions are indexed against whatever it returns, which is what makes
`Submission::find()->relatedToElement($element)` see them - `RelationFormField`
and `FileUpload` already do this, and everything else returns an empty list.

## Custom integrations

```php
use bytesof\formable\services\Integrations;
use bytesof\formable\events\RegisterIntegrationTypesEvent;
use yii\base\Event;

Event::on(
    Integrations::class,
    Integrations::EVENT_REGISTER_INTEGRATION_TYPES,
    function (RegisterIntegrationTypesEvent $event) {
        $event->types[] = \mymodule\integrations\Zapier::class;
    },
);
```

Extend [`Integration`](../src/base/Integration.php) (or the `Crm` /
`EmailMarketing` bases for those categories) and implement:

- `type()`, `displayName()`, `category()` - identity.
- `getSettingsSchema()` - the builder settings + field mapping UI.
- `isConfigured()` - whether it has what it needs to run.
- `send(Submission $submission, array $mapped)` - do the work, return an
  `IntegrationResponse`.

Integration forwarding is a **Pro** feature - a custom integration is
configurable in Lite but only runs on Pro, matching the built-ins.

## Captchas

Captchas are not an extension point. The four providers in
[src/captchas/](../src/captchas/) are the whole set, and there is no event to
register another - [`Captcha`](../src/base/Captcha.php) is internal, and
subclassing it won't make a provider appear in the form settings.
