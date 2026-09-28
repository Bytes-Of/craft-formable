# GDPR & data protection

Forms collect personal data, so Formable defaults to collecting **nothing** the
author didn't explicitly ask for, and gives you the controls to minimise, secure,
and expire what you do collect.

## Data minimisation - opt-in metadata

Two per-form toggles govern the metadata stored alongside a submission. Both are
**off by default**:

| Setting                | Default | Effect when on                                    |
| ---------------------- | ------- | ------------------------------------------------- |
| **Collect IP Address** | off     | Stores the submitter's IP with the submission.    |
| **Collect User Agent** | off     | Stores the submitter's browser user-agent string. |

IP capture is doubly gated: the form's _Collect IP Address_ setting **and**
Craft's own `storeUserIps` general-config flag must both be on. If either is off,
no IP is stored - Formable respects the site-wide Craft setting.

Leave both off and a submission holds only the field values the person entered.

## Encrypt sensitive fields at rest

Any field can be marked **Encrypt value** in its settings. When on:

- The value is encrypted at rest using Craft's security key.
- It's kept out of the search index, so it isn't searchable, and it never
  appears in the submission's title.
- It's masked as `••••••` in the ordinary submissions export. Exporting the
  plaintext is a separate export and a separate permission - see
  [below](#exporting-encrypted-values).
- It's decrypted when read by someone with access: on the submission's page in
  the control panel, in a notification email whose template asks for the field,
  and - over GraphQL - by a schema with the read scope.

Use it for anything genuinely sensitive (ID numbers, health details). See
[fields.md](fields.md#settings-every-field-shares).

## Consent

The **Agree** field is a dedicated consent checkbox: a statement (which may link
to your privacy policy) that must be ticked to submit. It's kept distinct from a
generic checkbox precisely so consent is recorded explicitly. See
[fields.md](fields.md#choice).

## Retention - don't keep it longer than you need (Pro)

A form can **auto-purge** completed submissions after a retention window - the
"don't keep it longer than you need it" control.

| Setting                     | Default | Effect                                                            |
| --------------------------- | ------- | ----------------------------------------------------------------- |
| **Data retention**          | off     | When on, completed submissions are auto-deleted after the window. |
| **Retention period (days)** | 90      | How long a completed submission is kept.                          |

The purge is started by Craft's garbage-collection cycle and runs as a
background queue job, a few hundred submissions at a time, so a large backlog
is worked through over several jobs rather than in one request. Retention is a **Pro**
feature: in Lite the purge is a no-op and submissions are kept until someone
deletes them manually.

## Incomplete data - multi-page forms and save & resume

Walking through a multi-page form stores **nothing**. The answers given so far
are held in the submitter's own session until they press Submit, so a form
abandoned on page two leaves no record behind - not in the database, and not for
the 30 days a stored partial would have lasted.

The one thing that does store a part-filled form is **save & resume** (Pro):
the submitter asks for a link so they can finish later, and that request is what
justifies keeping personal data they never finished sending. It's **opt-in per
form**, it only ever stores what the submitter deliberately parked, and the
stored partials **expire on their own** (default 30 days). Off in Lite, where
there is no way to store a partial at all.

The save step is also rate limited, so it can't be scripted in a loop to fill
the table with unfinished submissions (or to flood a mailbox with resume links):
one IP address gets a few saves per form in a short window and further attempts
are ignored. The completed submit (and a multi-page form's last Next) carries
its own separate limit, higher by default since a genuine shared connection is
far more plausible there. Both caps and windows are under **Formable →
Settings → Spam protection**, and setting either to 0 turns that limit off. See
[Rate limiting](getting-started.md#rate-limiting) for the full behaviour,
including what's logged when a limit is reached.

## Delivery logs

Formable logs every notification and integration delivery, so a failed one can
be seen and resent from the form builder. Those logs hold personal data of
their own, so they are kept small and short-lived:

- A **notification log** entry records the recipients and the subject line.
- An **integration log** entry records whether the delivery worked. Only a
  **failed** delivery also keeps the request that was sent and the response
  that came back, for diagnosis, and any answer to an **Encrypt value** field is
  masked as `••••••` in that copy.
- Both logs are cleared of entries older than **Delivery Log Retention (days)**,
  under **Formable → Settings → Data protection**, on Craft's
  garbage-collection cycle. The default is 90 days, and 0 keeps them
  indefinitely.

Permanently deleting a submission removes its integration log entries
straight away. Its notification log entries stay, with no link to the
submission, as a record that an email was sent, until the retention window
clears them.

## Right to erasure

To fulfil an erasure request, delete the submission from **Formable →
Submissions** and then **delete it permanently**. A plain delete moves a
submission to Craft's trash, where it can still be restored, and it isn't
erased until it's permanently deleted there or until Craft's garbage
collection purges it after the
[`softDeleteDuration`](https://craftcms.com/docs/5.x/reference/config/general.html#softdeleteduration)
(30 days by default). Retention (above) deletes permanently from the start.

A permanent delete removes:

- the submission, its answers, notes and edit history, and its entries in the
  search index,
- **the files uploaded with it**, unless you turn off **Delete Uploads With
  Submissions** under **Formable → Settings → Data protection**. Only files
  Formable itself uploaded for that submission are deleted, never an asset
  that was already in the volume,
- its integration log entries.

It can't reach:

- **email that has already been sent.** Notifications, and any files attached
  to them, are in the recipients' mailboxes.
- **data already forwarded to an integration.** A CRM, mailing list or webhook
  receiver keeps its own copy, and you need to erase it there as well.
- **files uploaded on a form with Store submissions turned off**, and files
  uploaded on an earlier page of a multi-page form that was never submitted.
  There's no stored submission to own them, so they stay in the volume until
  you delete them.
- **your own backups** of the database and the volume.

## Export & portability

Submissions can be exported to CSV/JSON from the submissions index for data
subject-access or portability requests. (This is distinct from _form_ export,
which moves a form's definition between installs and never includes submission
data or provider secrets - see [import-export.md](import-export.md).)

A CSV export prefixes any answer that starts with `=`, `+`, `-` or `@` (or a tab
or carriage return) with an apostrophe, so a spreadsheet shows it as text rather
than running it as a formula - the answers come from anonymous visitors, and
you'll open the file in Excel. Spreadsheets hide the apostrophe. JSON and XML
exports are left exactly as submitted, and so are plain numbers like `-5`.

### Exporting encrypted values

The export HUD offers two exports:

| Export                                       | Encrypted fields   | Who can run it                               |
| -------------------------------------------- | ------------------ | -------------------------------------------- |
| **Submissions**                              | masked as `••••••` | anyone with _View submissions_               |
| **Submissions (including encrypted values)** | in the clear       | also needs **Export encrypted field values** |

An export is a plaintext file that leaves Craft's access controls behind the
moment it downloads, so the decision to take encrypted answers with it is one
somebody makes deliberately rather than one the default export makes for them.
The second export exists because a subject access request needs the plaintext -
that is what it's for.

Grant **Export encrypted field values** under _Users → (group or user) →
Permissions → Formable → View submissions_. An admin has it, as they have every
permission; nobody else does until you say so. A field left blank exports as
blank in both - a mask would claim an answer that was never given.
