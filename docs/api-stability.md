# API stability

Formable follows [semantic versioning](https://semver.org). This page says what
that promise covers: the parts of the plugin you can build on without pinning an
exact version, and the parts you can't.

The short version: **if it isn't listed as stable here, or tagged `@api` in the
source, treat it as internal.** Internal code changes in any release, patch
releases included, without a changelog entry.

## How the code is marked

Every PHP class in `src/` carries one of two tags in its class docblock:

| Tag         | Meaning                                                              |
| ----------- | -------------------------------------------------------------------- |
| `@api`      | Stable. Covered by the versioning rules below.                       |
| `@internal` | Not for outside use. May change or disappear in any release.         |

- **A member's own tag beats its class's.** An `@api` class can hold `@internal`
  methods (a storage detail on an element, say), and an `@internal` class can
  hold `@api` members - the event constants on the services are the case that
  matters, since you listen for them on a service class you otherwise shouldn't
  touch.
- **A member of an `@api` class with no tag of its own is stable.** That
  includes protected members you override when you extend it. A method that
  merely overrides one of Craft's own (`afterSave()`, `canView()`,
  `defineRules()`) follows Craft's contract for that method, not ours.
- **Untagged means internal.** The tags exist so tooling can tell you: PHPStan
  reports a use of an `@internal` element from outside the plugin's namespace,
  and IDEs grey it out. The build fails if a class carries neither tag.

The same tags mark the JavaScript entry points in
[`main.ts`](../src/web/assets/frontend/main.ts). The control-panel builder
(`src/web/assets/formbuilder/`) is internal throughout.

## What is stable

### PHP

| Surface | Members |
| ------- | ------- |
| **Field types** | `FormFieldInterface` and the bases you extend: `FormField`, `OptionsFormField`, `RelationFormField`, `CompositeFormField`, `CosmeticFormField`. Registered with `Fields::EVENT_REGISTER_FIELD_TYPES`. |
| **Integrations** | `Integration` and its category bases `Crm` and `EmailMarketing`, plus `IntegrationResponse`, the value your `send()` returns. Registered with `Integrations::EVENT_REGISTER_INTEGRATION_TYPES`. |
| **Presets** | `Preset`, registered with `Presets::EVENT_REGISTER_PRESETS`. |
| **Options** | `FieldOption`, what an options field's `getOptions()` returns. |
| **Submission events** | `Submissions::EVENT_BEFORE_SUBMIT`, `EVENT_AFTER_SUBMIT`, `EVENT_BEFORE_SAVE_SUBMISSION`, `EVENT_AFTER_SAVE_SUBMISSION`, `EVENT_SUBMISSION_ERROR`, `EVENT_AFTER_SAVE_INCOMPLETE`, and the event classes `SubmissionEvent`, `SubmissionErrorEvent`, `RegisterIntegrationTypesEvent` and `RegisterPresetsEvent`. |
| **Elements** | `Form` and `Submission`, and their query classes `FormQuery` and `SubmissionQuery` - the way to read Formable's data from PHP. Their `@internal` members are the storage representation (`getFormData()`, `getPages()` and their setters) and the save-and-resume state (`resumeToken`, `pageIndex`, `dateExpired`). |
| **Twig** | `FormableVariable`, i.e. everything under `craft.formable`, and the `FormShell` that `renderFormShell()` returns. |
| **Config** | `Settings`. Its public properties are the keys of `config/formable.php`, and its `THEME_LEVEL_*` and `COLOR_SCHEME_*` constants are their values. |
| **Editions** | `Plugin::isPro()` and the `Plugin::EDITION_LITE` / `EDITION_PRO` constants. Use them to gate a feature of your own the way Formable gates its own. `Plugin::getInstance()` is Craft's. |

Everything else is `@internal`. In particular:

- **The services** other than their event constants - `Submissions`,
  `Rendering`, `Notifications`, `Integrations`, `Layout` and the rest. Read
  submissions through `Submission::find()` and forms through `Form::find()`,
  and hook the pipeline through its events.
- **The built-in field, integration and captcha classes.** They are `final`
  (bar `Text`) and `@internal`. Extend a base, not `Email` or `Webhook`.
- **Captchas.** `Captcha` is internal because there is no registration event to
  add a provider with; the four built-ins are the whole set.
- **Records, tables, migrations, queue jobs, controllers, GraphQL resolvers and
  types, helpers, the console controllers' classes, exporters and element
  actions.** Read the tables through the element queries. A queued job's class
  name is not a contract either, so drain the queue before upgrading a site
  that runs a long one.

### Front end

- **`window.Formable.init(root?)`** and **`window.FormableForm.forHandle()`**;
  see [JavaScript lifecycle](templating.md#javascript-lifecycle). Nothing else
  on `FormableForm`, and no other module in the bundle, is stable.
- **The four `formable:*` events** (`success`, `error`, `saved`, `closed`) and
  the properties of `event.detail` that page documents.
- **The template pack's file names and directory layout**, and
  `craft.formable.template()`, which is what lets an override replace one
  template and keep the rest. See [Overriding
  templates](templating.md#overriding-templates).
- **The `data-formable-*` attributes and `formable-*` class names** the
  [templating](templating.md) and [theming](theming.md) pages name, and every
  `--formable-*` token in the [token reference](theming.md#token-reference). A
  token is never removed without a back-compat alias for the rest of that major
  version.

Template overrides are the softest part of this contract, because an override
replaces markup Formable owns. What you can rely on is the list above plus the
`field` properties named for each setting in [Fields](fields.md). Other
variables in scope inside a pack template (the shape of a page or row array,
for instance) are the pack's own plumbing: when a minor release adds markup or
an attribute to a template, an override written against an older copy simply
doesn't have it yet. Re-diff your overrides against the pack when you upgrade.

### Data and endpoints

- **The GraphQL schema** in [Headless & GraphQL](headless-graphql.md): the
  type, query and mutation names, their arguments, and the schema scope
  entity.
- **The console commands** `formable/forms/list|export|import`,
  `formable/submissions/reindex` and `formable/digest/run|send|test`, with their
  options and exit codes. The controller classes behind them are internal.
- **The form export format.** A file written by any 1.x release imports into
  every later 1.x release. An incompatible change to the format bumps its
  `formatVersion` and ships in a major release.
- **Permission handles** (`formable:viewForms` and the rest, listed in
  [Permissions](permissions.md)), since they are stored against user groups.
- **The front-end submit endpoint** documented in
  [Headless & GraphQL](headless-graphql.md#the-front-end-submit-endpoint).

The database schema is not on this list. Each release that changes it ships a
migration, but code that reads the tables directly is on its own.

## Versioning rules

| Release | May contain |
| ------- | ----------- |
| **Patch** (1.0.x) | Bug fixes and internal changes. Nothing stable changes shape. |
| **Minor** (1.x.0) | New stable surface. Deprecations. Behaviour changes that only fix a case the docs already described the other way. |
| **Major** (x.0.0) | Removals, and any change to a stable surface that breaks code written against the old one. |

What counts as **breaking** for the stable surfaces above:

- Removing or renaming a stable class, method, property, constant, event,
  console command, GraphQL field, token or template.
- Changing a stable method's signature, its return type, or what an event
  handler is allowed to do to the event.
- Adding an **abstract** method to a base class, or any method to
  `FormFieldInterface`. This is why the docs tell you to extend `FormField`
  instead of implementing the interface: new methods land on the base with a
  default, which is a minor change.
- Moving a feature from Lite to Pro. A feature that works in Lite stays
  working in Lite for the life of the major version.
- Raising the minimum PHP or Craft version, apart from dropping a release its
  own vendor no longer supports, which a minor release may do and the
  changelog will say.

What does **not** count as breaking:

- Adding a public or protected method with a default implementation to a base
  class, a property to an event, a key to an array a stable method returns, a
  setting, a field type or a token. **Prefix your own subclass methods and
  properties** (`myModuleFoo()`), so an addition on our side can't collide with
  one on yours.
- Changing a stable method's behaviour to fix a bug, or making it stricter
  about input it was already documented to reject.
- Anything `@internal`.

## Deprecation

A stable element is never removed without a warning first:

1. It is tagged `@deprecated` with the version it was deprecated in and what to
   use instead, and calling it logs a `Craft::$app->getDeprecator()` entry, so
   the warning shows up in the control panel's deprecation log on a site that
   still uses it.
2. The changelog lists it under **Deprecated** in that release.
3. It stays, working, for the rest of that major version, and is removed in the
   next one.

A change that can't be given a warning period - a security fix that has to
close an interface, say - is called out in the changelog as such and made in
the earliest release that can carry it.
