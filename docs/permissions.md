# Permissions

Formable registers its own permissions under **Users → (a user or group) →
Permissions → Formable**. Grant only what a role needs - an admin has every
permission automatically, as they have every permission in Craft.

## Access Formable comes first

Every permission below assumes the holder can already reach a Formable screen
in the control panel. That's a separate check, and it isn't one of Formable's
own permissions: any plugin's CP routes require Craft's own **Access Formable**
permission (`accessPlugin-formable`), enforced in `craft\web\Application`
before a request ever reaches Formable's controllers. A user who holds only
`formable:manageIntegrations` - which, unlike the rest of the list, doesn't
nest under a `view*` parent - still needs Access Formable granted separately,
or Craft returns a 403 before Formable's own permission check runs.

## The full list

| Permission                              | Handle                        | Grants                                                                 |
| ---------------------------------------- | ------------------------------ | ----------------------------------------------------------------------- |
| View forms                              | `formable:viewForms`          | See **Formable → Forms** and open a form in the builder, read-only.   |
| &nbsp;&nbsp;Create and edit forms       | `formable:saveForms`          | Save changes in the builder; also gates the author-facing preview and resending a failed notification or integration delivery (see [Resending a failed delivery](notifications-integrations.md#resending-a-failed-delivery)). Nested under *View forms*. |
| &nbsp;&nbsp;Delete forms                | `formable:deleteForms`        | Delete a form. Nested under *View forms*.                              |
| View submissions                        | `formable:viewSubmissions`    | See **Formable → Submissions** and open one.                          |
| &nbsp;&nbsp;Edit submissions            | `formable:saveSubmissions`    | Change a submission's status, add notes. Nested under *View submissions*. |
| &nbsp;&nbsp;Delete submissions          | `formable:deleteSubmissions`  | Delete a submission. Nested under *View submissions*.                  |
| &nbsp;&nbsp;Export encrypted field values | `formable:exportSensitive`  | Offers a second, plaintext export alongside the ordinary one, which masks encrypted answers. Nested under *View submissions* - see [GDPR & data protection](gdpr.md). |
| Manage integrations                     | `formable:manageIntegrations` | See and edit **Formable → Integrations** - add, configure, test and delete integration instances. |
| Manage submission statuses              | `formable:manageStatuses`     | See and edit **Formable → Statuses** - add, rename, recolour, reorder and delete workflow statuses. |

Every permission except the two at the bottom nests under a `view*` parent the
same way Craft's own permissions do: granting a child implicitly requires (and
in the CP, auto-grants) its parent.

## Two things a permission alone doesn't get you

**Statuses are still gated by `allowAdminChanges`, for everyone.** Statuses
live in [project config](getting-started.md#submission-statuses), so a change
made in the control panel is meant to be built on a development site and
deployed with the rest of your configuration - not made directly against
staging or production. `Formable → Statuses` is unavailable, and the
underlying controller refuses, whenever your environment has
[`allowAdminChanges`](https://craftcms.com/docs/5.x/reference/config/general.html#allowadminchanges)
set to `false`, regardless of who holds `formable:manageStatuses` - the same
bar Craft applies to its own project-config editors (Sections, Sites, Global
Sets). Grant the permission to whoever should be able to add or rename a
status on a development environment; it does nothing for them once that
environment locks admin changes down.

**The global Formable settings screen (`Settings → Plugins → Formable`) stays
admin-only, full stop.** It has no `formable:*` permission of its own to grant,
because it isn't Formable's own screen - it renders through Craft core's
plugin-settings route, which requires a full admin account unconditionally.
There is no way to delegate access to it without replacing that route with a
plugin-owned one, which Formable does not do. If a non-admin needs to change a
setting that lives there (captcha keys, the digest schedule, global palette),
an admin has to make the change, or grant that person admin.

**Integrations are the exception to both of those.** `IntegrationRecord`s are
a plain database table, not project config - there's a real security reason
they were admin-only (third-party credentials), but no deployment reason. So
`formable:manageIntegrations` is a plain, unconditional permission: grant it to
a forms operator or an agency's client contact directly, on production,
without also unlocking admin changes and without making them an admin.

**Grant it knowing what it actually exposes.** It amounts to more than
configuration access. Anyone who can repoint an integration - change a
Webhook's URL, for instance - can read every submission of every form mapped
to it, encrypted field values included: `Webhook::submissionData()` resolves
each value through `Submission::getValue()`, which decrypts. In effect,
`formable:manageIntegrations` carries `formable:viewSubmissions` and
`formable:exportSensitive` for every form that integration touches. That may
still be the right call for a forms operator or client contact - just grant it
with that in mind, not as a configuration-only permission.

What that person **can't** do is aim the server at your internal network:
integrations refuse private and link-local addresses unless an admin has
allowed them in Settings. See [Where integrations may
send](notifications-integrations.md#where-integrations-may-send).

## Resending a failed delivery

Resending a failed notification or integration delivery from a form's log
needs `formable:saveForms` - the same permission as editing the form itself,
not either of the two above. See
[Resending a failed delivery](notifications-integrations.md#resending-a-failed-delivery).

## Authoring Twig in a form's settings

A success, error or closed message, a redirect URL, an upload subfolder, and
a notification's subject, body and addresses all accept Twig, and all of them
are written by whoever holds `formable:saveForms` - which does not require an
admin account. That Twig runs sandboxed: Craft's own sandbox policy
(`config/twig-sandbox.php`, overridable per site) applies, which keeps
`craft`, `currentUser` and every other global out of reach, along with any
tag, filter, function or method it doesn't explicitly allow. A form's own
submitted field values are the one addition to that policy - `{{ name }}` or
`{name}` in a message resolves a submission's `name` field the same way
`{{ object.id }}` already could, sandboxed or not.

An HTML block field's content and an Agree field's description are purified
(Craft's `|purify` filter, backed by `HtmlPurifier`) rather than printed raw,
for the same reason: authoring either needs only `formable:saveForms`, not an
admin account.
