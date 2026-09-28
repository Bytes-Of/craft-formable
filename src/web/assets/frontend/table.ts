/**
 * Repeatable rows for the Table field.
 *
 * The field renders `minRows` rows and an "add a row" button; this is what
 * makes the button work, and what adds the per-row remove control (which only
 * exists with JavaScript, so the server never renders one).
 *
 * Rows are cloned from the last rendered row rather than built from a column
 * spec held in JavaScript: the server already knows how to render a cell for
 * every column type, and a clone can't drift from it. The one thing a clone
 * can't carry is its own position, so every mutation renumbers the whole body -
 * `fields[handle][<row>][<column>]` is what PHP groups rows by, and a gap or a
 * duplicate there silently loses a row.
 */

type Cell = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

const SELECTOR =
  '[data-formable-table]:not([data-static]):not([data-formable-table-ready])';
const CELLS = 'input, select, textarea';

/** Splits `fields[handle][3][email]` into its base, row index and column handle. */
const CELL_NAME = /^(.*)\[(\d+)\]\[([^\]]+)\]$/;

/** Fallback id stem, for markup whose cell ids don't follow the shipped scheme. */
let idCounter = 0;

interface Layout {
  /** Everything before the row index: `fields[handle]`. */
  nameBase: string;
  /** Everything before `-<row>-<column>` in a cell id. */
  idBase: string;
}

export function enhanceTables(root: ParentNode): void {
  root
    .querySelectorAll<HTMLTableElement>(SELECTOR)
    .forEach((table) => setUp(table));
}

function setUp(table: HTMLTableElement): void {
  const body = table.tBodies[0] as HTMLTableSectionElement | undefined;
  const firstRow = body?.rows[0];
  const layout = firstRow ? readLayout(firstRow) : null;

  // Nothing to clone, or markup this module doesn't recognise (a template
  // override that renders its own cell names). Left unclaimed and untouched -
  // the rows the server rendered still post normally.
  if (!body || !layout) {
    return;
  }

  table.dataset.formableTableReady = 'true';

  const field = table.closest<HTMLElement>('[data-formable-field]');
  const addButton =
    field?.querySelector<HTMLButtonElement>('[data-formable-table-add]') ??
    null;
  const removeLabel = table.dataset.removeLabel ?? 'Remove row';
  const rowAddedLabel = table.dataset.rowAddedLabel ?? 'Row added';
  const rowRemovedLabel = table.dataset.rowRemovedLabel ?? 'Row removed';
  const maxRows = Number(table.dataset.maxRows) || null;
  // At least one row always stays, whatever the field's minimum: an empty table
  // has nothing left to clone from.
  const minRows = Math.max(1, Number(table.dataset.minRows) || 0);

  addRemoveColumn(table, removeLabel);

  // A polite status region for a row appearing or disappearing - the focus
  // move onto the new or shifted-up row doesn't by itself say what changed.
  const notice = document.createElement('p');
  notice.className = 'formable-field__notice';
  notice.setAttribute('role', 'status');
  notice.setAttribute('aria-live', 'polite');
  notice.hidden = true;
  (
    table.closest<HTMLElement>('.formable-table-scroll') ?? table
  ).insertAdjacentElement('afterend', notice);

  const setNotice = (message: string): void => {
    notice.textContent = message;
    notice.hidden = message === '';
  };

  const refresh = (): void => {
    const count = body.rows.length;

    if (addButton) {
      addButton.disabled = maxRows !== null && count >= maxRows;
    }

    // Disabled rather than hidden at the floor, so the control doesn't shift
    // the layout as rows come and go.
    table
      .querySelectorAll<HTMLButtonElement>('[data-formable-table-remove]')
      .forEach((button) => {
        button.disabled = count <= minRows;
      });
  };

  const changed = (): void => {
    renumber(table, body, layout);
    refresh();

    // Rows are values: conditional logic listening on the form has to see that
    // the field's contents changed.
    table.dispatchEvent(new Event('change', { bubbles: true }));
  };

  addButton?.addEventListener('click', () => {
    if (maxRows !== null && body.rows.length >= maxRows) {
      return;
    }

    const row = blankRowFrom(body.rows[body.rows.length - 1], removeLabel);
    body.appendChild(row);
    changed();
    setNotice(rowAddedLabel);

    // The new row is where the visitor is going next, and after an AJAX-free
    // click there's nothing else to tell them it appeared.
    row.querySelector<Cell>(CELLS)?.focus();
  });

  // Delegated, so a cloned row's remove button works without being wired up.
  table.addEventListener('click', (event) => {
    const target = event.target as HTMLElement | null;
    const button = target?.closest<HTMLElement>('[data-formable-table-remove]');
    const row = button?.closest('tr');

    if (!row || body.rows.length <= minRows) {
      return;
    }

    // Removing the focused row's button drops focus to `<body>` the instant
    // the row leaves the DOM, so where it lands next is decided *before* that
    // happens: the row that will shift up to take this one's place, or the
    // add button if this was the last row.
    const rows = Array.from(body.rows);
    const nextRow = rows[rows.indexOf(row) + 1] ?? null;

    row.remove();
    changed();
    setNotice(rowRemovedLabel);

    if (nextRow) {
      nextRow.querySelector<Cell>(CELLS)?.focus();
    } else {
      addButton?.focus();
    }
  });

  renumber(table, body, layout);
  refresh();
}

/**
 * Reads the naming scheme off a rendered cell.
 *
 * The id base is derived by stripping the exact `-<row>-<column>` suffix the
 * template appended, rather than by pattern-matching it: a form id or a column
 * handle containing digits or dashes would make any regex guess wrong.
 */
function readLayout(row: HTMLTableRowElement): Layout | null {
  const cell = row.querySelector<Cell>(CELLS);
  const match = cell ? CELL_NAME.exec(cell.name) : null;

  if (!cell || !match) {
    return null;
  }

  const [, nameBase, index, handle] = match;
  const suffix = `-${index}-${handle}`;

  return {
    nameBase,
    idBase: cell.id.endsWith(suffix)
      ? cell.id.slice(0, -suffix.length)
      : `formable-table-${(idCounter += 1)}`,
  };
}

/**
 * Rewrites every cell's name, id and label to match its row's position.
 *
 * Runs over the whole body on every change: removing row 2 of 5 renumbers
 * three others, so there's no cheaper correct version.
 */
function renumber(
  table: HTMLTableElement,
  body: HTMLTableSectionElement,
  layout: Layout,
): void {
  const headings = table.tHead?.rows[0];

  Array.from(body.rows).forEach((row, rowIndex) => {
    row.querySelectorAll<Cell>(CELLS).forEach((cell) => {
      const match = CELL_NAME.exec(cell.name);

      if (!match) {
        return;
      }

      const handle = match[3];
      const container = cell.closest('td');
      const id = `${layout.idBase}-${rowIndex}-${handle}`;

      cell.name = `${layout.nameBase}[${rowIndex}][${handle}]`;
      cell.id = id;

      // The label is looked up inside the cell's own `<td>` rather than by its
      // `for`, which is mid-rewrite at this point.
      const label = container?.querySelector('label');
      const columnLabel = container
        ? headings?.cells[container.cellIndex]?.textContent?.trim()
        : undefined;

      if (!label) {
        return;
      }

      label.setAttribute('for', id);

      // The per-cell label names its column *and* its row ("Email, row 3"), so
      // a cloned row would otherwise announce the position it came from.
      // Recomposed from the translated pattern the template left behind.
      if (table.dataset.rowLabel !== undefined && columnLabel !== undefined) {
        label.textContent = table.dataset.rowLabel
          .replace('__COLUMN__', columnLabel)
          .replace('__ROW__', String(rowIndex + 1));
      }
    });
  });
}

/** Clones a rendered row and empties it, so the new row inherits every column's markup. */
function blankRowFrom(
  template: HTMLTableRowElement,
  removeLabel: string,
): HTMLTableRowElement {
  const row = template.cloneNode(true) as HTMLTableRowElement;

  row.querySelectorAll<Cell>(CELLS).forEach((cell) => {
    if (cell instanceof HTMLSelectElement) {
      cell.selectedIndex = 0;
    } else if (cell instanceof HTMLInputElement && cell.type === 'checkbox') {
      cell.checked = false;
    } else {
      cell.value = '';
    }

    // A clone of a row the server marked invalid must not carry the error over.
    cell.removeAttribute('aria-invalid');
  });

  ensureRemoveCell(row, removeLabel);

  return row;
}

/**
 * Adds the remove column's header, and a remove button to every rendered row.
 *
 * The column is added here rather than in Twig because removal is only ever
 * possible with JavaScript - rendering the header server-side would leave a
 * permanently empty column on a page whose JS didn't load.
 */
function addRemoveColumn(table: HTMLTableElement, removeLabel: string): void {
  const headings = table.tHead?.rows[0];

  if (headings && !headings.querySelector('[data-formable-table-actions]')) {
    const heading = document.createElement('th');
    heading.scope = 'col';
    heading.dataset.formableTableActions = '';

    // The column has no visible heading, but an unlabelled one is announced as
    // "blank" and leaves the row's buttons without a column name.
    const text = document.createElement('span');
    text.className = 'formable-visually-hidden';
    text.textContent = removeLabel;
    heading.appendChild(text);
    headings.appendChild(heading);
  }

  Array.from(table.tBodies[0]?.rows ?? []).forEach((row) =>
    ensureRemoveCell(row, removeLabel),
  );
}

function ensureRemoveCell(row: HTMLTableRowElement, removeLabel: string): void {
  if (row.querySelector('[data-formable-table-remove]')) {
    return;
  }

  const cell = row.insertCell();
  const button = document.createElement('button');
  button.type = 'button';
  button.className =
    'formable-button formable-button--ghost formable-button--sm formable-table__remove';
  button.dataset.formableTableRemove = '';
  button.textContent = removeLabel;
  cell.appendChild(button);
}
