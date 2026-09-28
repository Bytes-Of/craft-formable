# Headless & GraphQL (Pro)

Formable exposes a GraphQL surface for headless sites - query forms, read
submissions, and create submissions over the API. **GraphQL is a Pro feature:**
in Lite the types, queries, and mutation are not registered at all.

## Schema scopes

Access is granted **per form**, keyed off each form's UID, under a single scope
entity: `formableForms`. In **GraphQL → Schemas**, grant a schema:

- **read** on a form - lets it query that form and its submissions.
- **save** on a form - lets it create submissions against that form via the
  mutation.

Nothing is queryable or mutable by a schema that hasn't been granted the
matching scope. The `formableSubmissions` query and `saveFormableSubmission`
mutation only appear in the schema when at least one form grants the relevant
scope.

## Queries

### Forms

```graphql
{
  formableForms(handle: "contact") {
    id
    uid
    handle
    title
  }
}
```

- `formableForms` - a list of forms.
- `formableForm` - a single form.
- `formableFormCount` - a count.

Common arguments: `id`, `uid`, `handle`, `status`, `limit`, `offset`.

### Submissions

```graphql
{
  formableSubmissions(form: "contact", limit: 20) {
    id
    dateCreated
    ipAddress
    values {
      handle
      values
    }
  }
}
```

- `formableSubmissions` - a list of submissions.
- `formableSubmission` - a single submission.
- `formableSubmissionCount` - a count.

Each submission carries the site it was made from, as `submittedSiteId` and
`submittedSiteHandle`, and the list can be narrowed to it:

```graphql
{
  formableSubmissions(form: "contact", submittedSite: ["en", "de"]) {
    id
    submittedSiteHandle
  }
}
```

Forms themselves are global - one form is available on every site - so there is
no per-site form argument. A submission made before Formable recorded this, or
one made with no current site (a console replay), reports `null`.

Encrypted field values are deciphered on read, subject to the schema's read
scope. Fields marked non-public are omitted from the API.

## Mutation

Create a submission with `saveFormableSubmission`. It runs the **same
validation, spam checks and submit rate limit** as a posted form - the two
paths spend from one shared budget, configured under **Spam protection** in
the form's settings, so a cap set there holds regardless of which one a
submission arrives through.

```graphql
mutation {
  saveFormableSubmission(
    handle: "contact"
    fieldValues: [
      { handle: "name", value: "Ada Lovelace" }
      { handle: "email", value: "ada@example.com" }
      { handle: "interests", values: ["a", "b"] }
    ]
  ) {
    success
    submissionId
    submission {
      id
    }
    errors {
      handle
      messages
    }
    formErrors
    closed
  }
}
```

### Arguments

| Argument       | Type                 | Notes                                                                                      |
| -------------- | -------------------- | ------------------------------------------------------------------------------------------ |
| `handle`       | `String!`            | The form being submitted.                                                                  |
| `fieldValues`  | `[FieldValueInput!]` | One entry per field. Use `value` for single-valued fields, `values` for multi-valued ones. |
| `honeypot`     | `String`             | The honeypot value, when the form's honeypot check is on.                                  |
| `captchaValue` | `String`             | The captcha response token, when the form has a captcha (Pro).                             |

### Payload

| Field                         | Meaning                                                               |
| ----------------------------- | --------------------------------------------------------------------- |
| `success`                     | Whether the submission was accepted.                                  |
| `submissionId` / `submission` | The stored submission, when one was stored.                           |
| `errors`                      | Per-field validation errors, keyed by field handle.                   |
| `formErrors`                  | Errors not tied to a particular field.                                |
| `closed`                      | The form was closed - disabled, or outside its schedule window (Pro). |

### Multi-page forms

The mutation submits a form **in one shot**: send every field, from every page,
in a single call. There is no page-by-page mutation, no review step and no
save-and-resume over GraphQL - those belong to the
[front-end submit endpoint](#the-front-end-submit-endpoint), which carries a
form in flight in the submitter's session.

Multi-page forms still work headlessly, with two caveats. Conditional logic is
evaluated server-side against the values you send, so a field on a page your
values conditioned out is simply not required. And because the whole form
arrives at once, per-page validation never happens - every visible field is
validated together, and `errors` comes back for the form as a whole rather than
for a page.

If you need a paged headless flow today, drive the
[front-end submit endpoint](#the-front-end-submit-endpoint) with a client that
keeps cookies.

## The front-end submit endpoint

The paged flow above and Lite's headless path both post to the endpoint the
rendered form uses: the anonymous Craft controller action
`formable/submissions/submit`. It runs the full pipeline - conditions,
validation, spam, notifications, integrations - and is the only way to submit a
form page by page, walk a review step, or save a form for later.

Post `application/x-www-form-urlencoded` (or `multipart/form-data` when the form
has file fields) with:

| Parameter             | Notes                                                                                                          |
| --------------------- | ------------------------------------------------------------------------------------------------------------- |
| `action`              | `formable/submissions/submit`.                                                                                |
| `handle`              | The form being submitted.                                                                                     |
| `fields[<handle>]`    | One entry per field on the page being posted; an array for multi-valued fields.                               |
| `formableStep`        | `next`, `back`, `submit`, or `save` (save-and-resume, Pro). A single-page form sends `submit`.                 |
| `pageIndex`           | The zero-based index of the page these `fields` came from.                                                    |
| `formableFlow`        | Optional. Isolates one pass through the form from another that shares the same session (browser tabs). A client with its own cookie jar can omit it, or send a stable opaque string of its own on every step. |
| `formableGoto`        | With `formableStep=back`, the page index to jump to - the review step's "edit" links.                          |
| `formableReview`      | `1` when posting from the review step.                                                                        |
| `formableResumeEmail` | With `formableStep=save`, the address the resume link is sent to.                                             |

Craft's CSRF token is required unless CSRF protection is disabled. The in-flight
form lives in the session, so the client must carry the session cookie between
requests - that is what holds the answers from earlier pages together. As with
the mutation, the JavaScript and timing spam tokens are browser-planted and
won't be present, so a form leaning on those checks will treat a scripted post
as suspicious; send the honeypot field (its name is set in the plugin settings)
and, on the final step, the captcha token, where those checks are enabled.

With `Accept: application/json`, each step answers with JSON:

- **Moved to another page** - `{ success: true, page, review, html }`, where
  `html` is the next step's rendered markup.
- **Completed** - `{ success: true, submissionId, message, redirect }`.
- **Saved for later** - `{ success: true, saved: true, message }`.
- **Validation failed** - `{ success: false, message, errors, formErrors }`.

Without `Accept: application/json` the endpoint redirects after every step and
renders each page itself, so a non-JSON client must follow redirects and keep
cookies.

## Lite

In Lite, none of the above is present in the schema. If you need a headless
submission path on Lite, post to the
[front-end submit endpoint](#the-front-end-submit-endpoint) instead - the same
one `craft.formable.renderForm` wires up (it supports non-JS and AJAX posts).
