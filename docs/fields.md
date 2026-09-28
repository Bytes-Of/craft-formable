# Fields

Formable ships ~26 field types. You add them by dragging from the palette in the
form builder; each has a settings drawer.

## Settings every field shares

| Setting                            | What it does                                                                                                                                                                                                          |
| ---------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Label**                          | The field's visible label.                                                                                                                                                                                            |
| **Handle**                         | The key the value is stored and rendered under (e.g. `email`). Referenced in notifications, templates, and GraphQL. Renaming a saved field's handle moves its stored answers to the new one - see [Renaming a handle](#renaming-a-handle).                                                                                                   |
| **Instructions**                   | Help text shown beneath the label.                                                                                                                                                                                    |
| **Required**                       | Whether a value must be provided.                                                                                                                                                                                     |
| **Width**                          | How much of its row the field fills - `Auto`, `Full width`, `Half`, `Third` or `Quarter`. Every field on `Auto` (the default) splits the row equally, exactly as before. Size one or more and the rest share what's left, so "First name / Last name / Suffix" can give Suffix a quarter and let the two names split the remainder. Needs the stylesheet's `reset` layer or higher; ignored at level `none`.                                            |
| **CSS classes**                    | Extra classes added to the field wrapper, for styling.                                                                                                                                                                |
| **Hide label**                     | Keeps the label for screen readers but removes it visually - for search-box and inline-label layouts where a visible label would just repeat the surrounding design.                                                   |
| **Encrypt value** _(Pro-friendly)_ | Store this field's value encrypted at rest - for sensitive data. Encrypted values aren't searchable, don't appear in the submission title, and are masked in the ordinary submissions export. See [gdpr.md](gdpr.md). |
| **Conditions** _(Pro)_             | Show or hide the field based on other fields' values. Ignored in Lite (field always visible). See [templating.md](templating.md#conditional-logic-in-templates-pro).                                                  |

Presentational fields (Heading, HTML Block, Section Divider) render markup only -
they have no value, handle, or required state.

## Field reference

### Text input

| Field                | Purpose                                                                                                  |
| -------------------- | -------------------------------------------------------------------------------------------------------- |
| **Single-line Text** | Single-line text input.                                                                                  |
| **Multi-line Text**  | Multi-line textarea.                                                                                     |
| **Email Address**    | Email input with email validation. **Require Confirmation** adds a second box the submitter must match.  |
| **Website**          | URL input with URL validation.                                                                           |
| **Phone Number**     | Telephone input.                                                                                         |
| **Number**           | Numeric input, with optional min / max / step bounds.                                                    |
| **Hidden**           | Not shown to the submitter; populated from a static value or a request-time source (query string, etc.). |

### Choice

Dropdown, Multi-select, Radio Buttons and Checkboxes carry an editable
**options** list (label + value, with an optional default). Rating and Agree
don't - their choices are fixed by the field type itself.

| Field             | Purpose                                                                                                                                                                                    |
| ----------------- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------ |
| **Dropdown**      | Single-select dropdown.                                                                                                                                                                    |
| **Multi-select**  | Zero or more selections, as a searchable combobox with removable chips for what's picked. **Visible Rows** sets the height of the plain listbox a browser without JavaScript falls back to; it has no effect once the combobox is active. |
| **Radio Buttons** | Single choice from a visible group.                                                                                                                                                        |
| **Checkboxes**    | Zero or more selections from a group.                                                                                                                                                      |
| **Rating**        | A single rating from 1 to a configurable **Scale** (2-10, default 5). Unlike the other choice fields, its options aren't author-defined - they're always the whole numbers from 1 up to the scale. |
| **Agree**         | A single consent checkbox. The statement beside it supports inline HTML so it can link to a policy - kept distinct from Checkboxes because consent has its own semantics (must be ticked). |

### Date, name, address

| Field         | Purpose                                                                     |
| ------------- | --------------------------------------------------------------------------- |
| **Date/Time** | Date and/or time picker (configurable to date-only, time-only, or both).    |
| **Name**      | A person's name - one input, or split into sub-fields (first / last, etc.). |
| **Address**   | A postal address, rendered as its component sub-fields.                     |

**Date/Time** renders the browser's own date, time or date-and-time control -
no custom picker - so it is keyboard-accessible, localised and correct on
mobile without any configuration. The calendar UI looks different from one
browser to the next; the value posted and stored is always ISO 8601. Bound the
allowed range with **Earliest Date** / **Latest Date** on a field that collects
a date, or **Earliest Time** / **Latest Time** on a time-only field.

### Relations

Relation fields tie the submission to existing Craft elements.

| Field          | Purpose                                          |
| -------------- | ------------------------------------------------ |
| **Entries**    | Relate the submission to one or more entries.    |
| **Categories** | Relate the submission to one or more categories. |

A relation field's **Sources** setting picks which of the element type's
sources (sections, for Entries; category groups, for Categories) the
submitter can choose from - leave it empty to allow every native source. The
options offered on the rendered form are always live elements from those
sources, capped at 250 combined; past the cap the rest are left off and a
warning is logged. A posted id outside that set - disabled, unpublished, or
from a source the field wasn't scoped to - is rejected the same way any other
invalid answer is.

Users and Assets relation fields exist in the codebase (an existing form that
has one keeps working, unchanged) but aren't offered in the builder's field
palette: letting an anonymous form enumerate an install's user accounts or
files is a separate decision this release doesn't make.

### Files & signature

| Field           | Purpose                                                                                                                                          |
| --------------- | ------------------------------------------------------------------------------------------------------------------------------------------------ |
| **File Upload** | Upload one or more files into a configured **volume** and optional **subfolder**, with allowed file kinds, a max file size, and an upload limit. |
| **Signature**   | A drawn signature captured from a canvas and stored as a PNG data URL.                                                                           |

File Upload renders a plain file input. With the front-end bundle loaded it
also becomes a drop target, lists the chosen files with a per-file remove
control, and checks each file against the **max file size** and the **upload
limit** before anything is sent - so an oversize file is caught in the browser
rather than after it uploads. None of that is required: with JavaScript off the
input still selects and posts files, and the server enforces every constraint
regardless.

Signature needs JavaScript: the canvas is drawn on by the front-end bundle,
which writes the image into the hidden input that actually posts. There is no
way to sign without it, so don't mark a Signature field **required** on a form
whose visitors may have scripting disabled.

Drawing needs a pointing device, so the field also renders a text box: a
visitor types their name and it is rendered to the same canvas in a handwriting
face, producing the same stored value. This is the route for keyboard, screen
reader, switch and voice users - a required Signature field is satisfiable
without a mouse.

### Structured

| Field     | Purpose                                                                |
| --------- | ---------------------------------------------------------------------- |
| **Table** | Repeatable rows of typed columns - a mini grid the submitter fills in. |

Adding and removing rows needs JavaScript. Without it the submitter still gets
the **Min Rows** the field renders and can fill them in - so set a minimum that
is useful on its own if that matters to you, or switch **Fixed Rows** on and
render exactly the grid you want.

### Presentational (no value)

| Field               | Purpose                                                              |
| ------------------- | -------------------------------------------------------------------- |
| **Heading**         | A section heading rendered between fields.                           |
| **HTML Block**      | An arbitrary HTML block - intro copy, legal text, an embedded image. |
| **Section Divider** | Splits the fields around it into separate, bordered groups - drop one between two runs of fields to make a long form read as chunks rather than one flat list. Renders no line of its own; the boundary is the group's border. |

### System

| Field             | Purpose                                                                                                                                                                                                                                        |
| ----------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| **Missing field** | A stand-in Formable substitutes when a field's class can no longer be loaded (for example, a custom field type whose plugin was removed). It preserves the stored value and settings so nothing is lost - replace or remove it in the builder. |

## Adding your own field type

Field types are extensible: register a custom field class through
`Fields::EVENT_REGISTER_FIELD_TYPES` and extend the base classes in
[src/base/](../src/base/). See [extending.md](extending.md).

## Renaming a handle

A field's handle is the key its answers are stored under, so changing it on a
field that has already collected submissions could strand them. Formable follows
the rename instead. Fields are recognised by an internal ID that a handle change
doesn't touch, so when you save the form:

- Every stored submission of that form - completed, incomplete, spam, trashed -
  has its answer moved to the new handle. Relation fields' index moves with it.
  Conditions, a notification's conditional send and integration mappings that
  named the old handle are rewritten as you type the new one.
- The move runs in Craft's queue, in batches, so a form with many submissions
  saves at once and catches up as the queue is worked. Until it has, older
  submissions may show a blank for that field. A swap - two fields exchanging
  handles in one save - is handled as one move, not two.
- Text you wrote yourself is not rewritten. A `{oldHandle}` token in a
  notification's subject, body or addresses, and any template that reads the old
  handle, keep working only until you update them; the builder names the
  notifications it found after a save. Formable can't see your Twig templates.

Deleting a field and adding another with the same handle isn't a rename - the
new field simply picks up whatever was stored under that handle.

Deleting a field that something still points at stops the save rather than
leaving a rule that can never match. That covers a field's or page's
conditions, a notification's conditional send, and (on Pro) a switched-on
integration's conditions or field mapping. The error names the field, so fix or
remove what refers to it and save again. `formable/forms/import` refuses a file
with the same problem.
