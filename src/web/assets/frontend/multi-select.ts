/**
 * Multi-select enhancer.
 *
 * `multiSelect.twig` renders a native `<select multiple size>` - a listbox with
 * no search, no visible record of what's already picked beyond scrolling to
 * find the highlighted rows, and a discovery model (ctrl/cmd-click) most
 * visitors have never been taught. This replaces the visible surface with a
 * searchable combobox and a row of removable chips.
 *
 * The native select is not removed, only hidden - it stays exactly what posts
 * the value. Every chip added or removed here is mirrored onto the matching
 * `<option>`'s `.selected` flag and followed by a `change` event, so
 * conditional logic (which reads the form through the DOM, see
 * `conditions-dom.ts`) and the eventual form submission both see the native
 * select's own state and nothing else. `novalidate` is on every Formable
 * `<form>` (`form.twig`), so there is no native constraint-validation bubble to
 * preserve by keeping the select focusable - hiding it outright is safe.
 *
 * The select is hidden with inline styles rather than a CSS class: the reset
 * and theme stylesheets are optional (`themeLevel: 'none'`), and a hook that
 * only worked when a stylesheet happened to be loaded would leave a `none`
 * theme rendering the native listbox *and* this combobox stacked on top of
 * each other.
 *
 * Contract (see `enhancers.ts`): idempotent (claims the wrapper with
 * `data-formable-multiselect-ready`), progressive (the select still posts
 * without this - too few options, or a page this module never reaches, is left
 * exactly as `multiSelect.twig` rendered it), contained (a throw is caught by
 * `applyEnhancers`).
 */

import { scrollBehavior } from './scroll';

const SELECTOR =
  '[data-formable-multiselect]:not([data-formable-multiselect-ready])';

const LABEL_TOKEN = '__LABEL__';
const QUERY_TOKEN = '__QUERY__';

/** Mirrors the reset's `max-block-size: min(16rem, 50dvh)` for the flip check below. */
const LISTBOX_MAX_HEIGHT_PX = 256;

/** Applied with `cssText` rather than a class - see the header comment. */
const VISUALLY_HIDDEN_STYLE =
  'position:absolute;width:1px;height:1px;margin:-1px;padding:0;overflow:hidden;clip-path:inset(50%);white-space:nowrap;border:0;';

export function enhanceMultiSelects(root: ParentNode): void {
  root
    .querySelectorAll<HTMLElement>(SELECTOR)
    .forEach((wrapper) => setUp(wrapper));
}

function setUp(wrapper: HTMLElement): void {
  const select = wrapper.querySelector<HTMLSelectElement>('select[multiple]');
  const options = select ? Array.from(select.options) : [];

  // No select (a template override rendering something else), or nothing to
  // pick from. Left unclaimed and untouched: the field degrades to whatever
  // the server already rendered.
  if (!select || options.length === 0) {
    return;
  }

  wrapper.dataset.formableMultiselectReady = 'true';

  const nativeId = select.id;
  const describedBy = select.getAttribute('aria-describedby');
  const isInvalid = select.getAttribute('aria-invalid') === 'true';
  const isRequired = select.required;
  const listboxId = `${nativeId}-listbox`;

  const searchLabel = wrapper.dataset.formableMsSearchLabel ?? 'Search';
  const emptyLabelTemplate =
    wrapper.dataset.formableMsEmptyLabel ?? `No matches for “${QUERY_TOKEN}”`;
  const removeLabelTemplate =
    wrapper.dataset.formableMsRemoveLabel ?? `Remove ${LABEL_TOKEN}`;
  const addedLabelTemplate =
    wrapper.dataset.formableMsAddedLabel ?? `Added ${LABEL_TOKEN}`;
  const removedLabelTemplate =
    wrapper.dataset.formableMsRemovedLabel ?? `Removed ${LABEL_TOKEN}`;

  select.id = `${nativeId}-native`;
  select.removeAttribute('aria-describedby');
  select.removeAttribute('aria-invalid');
  select.setAttribute('aria-hidden', 'true');
  select.tabIndex = -1;
  select.style.cssText = VISUALLY_HIDDEN_STYLE;

  const control = document.createElement('div');
  control.className = 'formable-multiselect__control';

  const chips = document.createElement('ul');
  chips.className = 'formable-multiselect__chips';

  const search = document.createElement('input');
  search.type = 'text';
  search.id = nativeId;
  search.className = 'formable-multiselect__search';
  search.autocomplete = 'off';
  search.spellcheck = false;
  search.placeholder = searchLabel;
  search.setAttribute('role', 'combobox');
  search.setAttribute('aria-autocomplete', 'list');
  search.setAttribute('aria-expanded', 'false');
  search.setAttribute('aria-controls', listboxId);

  if (describedBy) {
    search.setAttribute('aria-describedby', describedBy);
  }

  if (isRequired) {
    search.setAttribute('aria-required', 'true');
  }

  if (isInvalid) {
    search.setAttribute('aria-invalid', 'true');
  }

  control.appendChild(chips);
  control.appendChild(search);

  const listbox = document.createElement('ul');
  listbox.id = listboxId;
  listbox.className = 'formable-multiselect__listbox';
  listbox.setAttribute('role', 'listbox');
  listbox.setAttribute('aria-multiselectable', 'true');
  listbox.hidden = true;

  const empty = document.createElement('p');
  empty.className = 'formable-multiselect__empty';
  empty.setAttribute('role', 'status');
  empty.hidden = true;

  // A separate region from `empty` above: that one reports the search's match
  // count and changes on every keystroke, so a chip being added or removed
  // gets its own polite announcement instead of being overwritten by the next
  // keystroke's result.
  const announce = document.createElement('p');
  announce.className = 'formable-field__notice';
  announce.setAttribute('role', 'status');
  announce.hidden = true;

  wrapper.appendChild(control);
  wrapper.appendChild(listbox);
  wrapper.appendChild(empty);
  wrapper.appendChild(announce);

  let activeValue: string | null = null;
  let wasOpen = false;

  const visibleOptions = (query: string): HTMLOptionElement[] => {
    const needle = query.trim().toLowerCase();

    if (needle === '') {
      return options;
    }

    return options.filter((option) =>
      option.text.toLowerCase().includes(needle),
    );
  };

  const isOpen = (): boolean => !listbox.hidden;

  const closeListbox = (): void => {
    listbox.hidden = true;
    wasOpen = false;
    search.setAttribute('aria-expanded', 'false');
    search.removeAttribute('aria-activedescendant');
    activeValue = null;
  };

  /**
   * Flips the popup to open upward when there isn't enough room below the
   * control - a sidebar near the foot of the page, a control near the bottom
   * of a modal, the on-screen keyboard covering the lower half of a phone
   * viewport. Only run on the closed-to-open transition (see `renderListbox`):
   * re-measuring on every keystroke would flip the popup out from under an
   * active selection as the match count (and so the popup's own height)
   * changes.
   */
  const positionOnOpen = (): void => {
    const controlRect = control.getBoundingClientRect();
    const spaceBelow = window.innerHeight - controlRect.bottom;
    const spaceAbove = controlRect.top;

    wrapper.classList.toggle(
      'formable-multiselect--flip',
      spaceBelow < LISTBOX_MAX_HEIGHT_PX && spaceAbove > spaceBelow,
    );

    control.scrollIntoView({ block: 'nearest', behavior: scrollBehavior() });
  };

  const setActive = (value: string | null): void => {
    activeValue = value;

    listbox.querySelectorAll('[data-formable-ms-option]').forEach((node) => {
      const el = node as HTMLElement;
      const isActive = el.dataset.value === value;

      el.classList.toggle('formable-multiselect__option--active', isActive);

      if (isActive) {
        search.setAttribute('aria-activedescendant', el.id);

        if (typeof el.scrollIntoView === 'function') {
          el.scrollIntoView({ block: 'nearest' });
        }
      }
    });

    if (value === null) {
      search.removeAttribute('aria-activedescendant');
    }
  };

  const renderListbox = (): void => {
    const query = search.value;
    const matches = visibleOptions(query);

    listbox.textContent = '';

    matches.forEach((option, index) => {
      const item = document.createElement('li');
      item.id = `${listboxId}-${index}`;
      item.className = 'formable-multiselect__option';
      item.dataset.formableMsOption = 'true';
      item.dataset.value = option.value;
      item.textContent = option.text;
      item.setAttribute('role', 'option');
      item.setAttribute('aria-selected', String(option.selected));

      if (option.disabled) {
        item.classList.add('formable-multiselect__option--disabled');
      } else {
        item.classList.add('formable-multiselect__option--selectable');
      }

      if (option.selected) {
        item.classList.add('formable-multiselect__option--selected');
      }

      listbox.appendChild(item);
    });

    listbox.hidden = matches.length === 0 || document.activeElement !== search;
    empty.hidden = matches.length > 0;
    empty.textContent =
      matches.length === 0
        ? emptyLabelTemplate.replace(QUERY_TOKEN, query)
        : '';

    if (!listbox.hidden && !wasOpen) {
      positionOnOpen();
    }

    wasOpen = !listbox.hidden;

    search.setAttribute('aria-expanded', String(!listbox.hidden));

    const selectableValues = matches
      .filter((option) => !option.disabled)
      .map((option) => option.value);

    setActive(
      activeValue !== null && selectableValues.includes(activeValue)
        ? activeValue
        : (selectableValues[0] ?? null),
    );
  };

  const renderChips = (): void => {
    chips.textContent = '';

    Array.from(select.selectedOptions).forEach((option) => {
      const chip = document.createElement('li');
      chip.className = 'formable-multiselect__chip';

      const label = document.createElement('span');
      label.className = 'formable-multiselect__chip-label';
      label.textContent = option.text;

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className = 'formable-multiselect__chip-remove';
      remove.dataset.formableMsRemove = option.value;
      remove.setAttribute(
        'aria-label',
        removeLabelTemplate.replace(LABEL_TOKEN, option.text),
      );
      remove.textContent = '×';

      chip.appendChild(label);
      chip.appendChild(remove);
      chips.appendChild(chip);
    });
  };

  const toggle = (value: string): void => {
    const option = options.find((candidate) => candidate.value === value);

    if (!option || option.disabled) {
      return;
    }

    const wasSelected = option.selected;
    option.selected = !wasSelected;
    select.dispatchEvent(new Event('change', { bubbles: true }));

    renderChips();
    renderListbox();

    const template = wasSelected ? removedLabelTemplate : addedLabelTemplate;
    announce.textContent = template.replace(LABEL_TOKEN, option.text);
    announce.hidden = false;
  };

  search.addEventListener('focus', renderListbox);
  search.addEventListener('input', renderListbox);

  search.addEventListener('keydown', (event) => {
    switch (event.key) {
      case 'ArrowDown':
      case 'ArrowUp': {
        event.preventDefault();

        if (!isOpen()) {
          renderListbox();

          return;
        }

        const matches = visibleOptions(search.value).filter(
          (option) => !option.disabled,
        );

        if (matches.length === 0) {
          return;
        }

        const currentIndex = matches.findIndex(
          (option) => option.value === activeValue,
        );
        const step = event.key === 'ArrowDown' ? 1 : -1;
        const nextIndex =
          (currentIndex + step + matches.length) % matches.length;

        setActive(matches[nextIndex].value);
        break;
      }

      case 'Enter':
        // A text input submits its form on Enter unless this is prevented -
        // true whether or not the popup happens to be open.
        event.preventDefault();

        if (isOpen() && activeValue !== null) {
          toggle(activeValue);
        }

        break;

      case 'Escape':
        if (isOpen()) {
          event.preventDefault();
          closeListbox();
        }

        break;

      case 'Backspace':
        if (search.value === '' && select.selectedOptions.length > 0) {
          toggle(
            select.selectedOptions[select.selectedOptions.length - 1].value,
          );
        }

        break;

      default:
        break;
    }
  });

  // Prevents the mousedown from moving focus off `search` before the `click`
  // handler below runs - without it, clicking an option closes the popup
  // (via the `focusout` handler) before the click is ever seen.
  listbox.addEventListener('mousedown', (event) => event.preventDefault());

  listbox.addEventListener('click', (event) => {
    const item = (event.target as HTMLElement).closest<HTMLElement>(
      '[data-formable-ms-option]',
    );

    if (
      !item ||
      item.classList.contains('formable-multiselect__option--disabled')
    ) {
      return;
    }

    toggle(item.dataset.value ?? '');
  });

  control.addEventListener('mousedown', (event) => {
    // A chip's remove button handles its own click below; anything else in
    // the control (the chip row's background, the search box itself) just
    // focuses the search input the normal way.
    if ((event.target as HTMLElement).closest('[data-formable-ms-remove]')) {
      return;
    }

    if (event.target !== search) {
      search.focus();
    }
  });

  chips.addEventListener('click', (event) => {
    const button = (event.target as HTMLElement).closest<HTMLButtonElement>(
      '[data-formable-ms-remove]',
    );

    if (!button) {
      return;
    }

    toggle(button.dataset.formableMsRemove ?? '');
    search.focus();
  });

  // `focusout` (bubbles, unlike `blur`) fires whenever focus leaves the
  // control - by keyboard or by a click the two `mousedown` guards above
  // didn't intercept - and `relatedTarget` is where it landed, so a click on
  // a chip's remove button (still inside `wrapper`) doesn't close the popup
  // out from under itself.
  wrapper.addEventListener('focusout', (event) => {
    if (!wrapper.contains(event.relatedTarget as Node | null)) {
      closeListbox();
    }
  });

  // Chips for whatever the server already selected - a re-rendered step or a
  // resumed submission - before the visitor ever opens the popup.
  renderChips();
}
