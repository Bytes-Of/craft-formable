# Import & export

Move a form's **definition** between installs as JSON - the workflow for
promoting a form from a dev or staging site to production. Two console commands
handle it, under `formable/forms`.

> This moves a form's _definition_, never its submissions. To export
> _submission data_ (for reporting or a subject-access request), use the CSV/JSON
> export on the **Formable → Submissions** index instead.

## What's in an export

`export` serialises everything needed to recreate the form:

- Title and handle
- Form settings
- The full page / row / field layout
- Notifications
- Integration field mappings
- Per-site translations of labels, instructions, options and messages (Pro)

Translations are keyed by site UID, not site ID, so they land on the matching
site when the target install shares the source's project config. Entries for a
site the target install doesn't have are kept but simply never shown. A Lite
install imports and stores them without using them, so upgrading to Pro later
brings them back.

**Provider secrets are never exported** - API keys, webhook URLs, and captcha
keys live in plugin settings, not in the form, so an export file is safe to
commit to a repo or hand off.

## Export

```bash
php craft formable/forms/export <handle>
```

Writes `<handle>.json` to the current directory. Options:

| Option          | Effect                                                                                                                                |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------- |
| `--path=<path>` | Write somewhere else. A directory receives `<handle>.json`; any other path is used as the filename. Craft path aliases are supported. |
| `--force`       | Overwrite an existing file without asking.                                                                                            |

Examples:

```bash
php craft formable/forms/export contact
php craft formable/forms/export contact --path=@root/forms/
php craft formable/forms/export contact --path=backups/contact.json --force
```

## Import

```bash
php craft formable/forms/import <path-to-json>
```

Import **upserts by handle**: if a form with the file's handle already exists it
is updated, otherwise a new one is created. Before writing anything, the command
validates the file and prints a summary:

```
Handle:        contact
Title:         Contact us
Action:        update
Pages:         2
Fields:        7
Notifications: 1
Translations:  2 site(s)
```

Options:

| Option      | Effect                                                                                                                                                           |
| ----------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `--dry-run` | Parse, validate, and print the summary **without writing anything**.                                                                                             |
| `--force`   | Required to overwrite an existing form with the same handle. Without it, an import that would update an existing form is refused (the guard against clobbering). |

Examples:

```bash
# See what would happen, change nothing
php craft formable/forms/import contact.json --dry-run

# Create a new form, or fail if the handle already exists
php craft formable/forms/import contact.json

# Update the existing form with that handle
php craft formable/forms/import contact.json --force
```

If the file is invalid (bad JSON, or a layout that fails validation) the command
reports the errors and writes nothing.

Every export records a `formatVersion`. A file from any 1.x release imports
into the same or any later 1.x release. A file exported by a **newer** release
than the one installed is refused, so update Formable on the target site first.
A file without `formatVersion` is read as version 1.

## Listing forms

```bash
php craft formable/forms/list   # or just: php craft formable/forms
```

Prints a table of every form - id, title, handle, and whether it's enabled -
handy for finding the handle to export.

## A typical promotion flow

```bash
# On the source site
php craft formable/forms/export contact --path=@root/forms/ --force

# Commit forms/contact.json, deploy, then on the target site
php craft formable/forms/import @root/forms/contact.json --dry-run
php craft formable/forms/import @root/forms/contact.json --force
```

Remember to configure the target site's integration/captcha credentials in
plugin settings - those intentionally don't travel with the form.
