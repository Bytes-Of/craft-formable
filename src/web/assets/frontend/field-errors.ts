/**
 * Field-level error markup, shared between every path that can mark one
 * field invalid on the front end: the server-driven AJAX response
 * (`main.ts`'s `handleErrors()`) and the client-only inline validation pass
 * (`validation-dom.ts`). Both have to produce the exact same DOM shape as the
 * page-reload path's server-rendered markup (`_field.twig`), or a field would
 * look and announce differently depending on which one caught the problem.
 */

/**
 * Attaches messages to one field, replacing any it already shows. Same
 * classes, same `aria-describedby` wiring, no `role="alert"` as the
 * server-rendered shape - see `_field.twig:99-110` and 0064 for why there's no
 * alert role here.
 */
export function renderFieldErrors(
  wrapper: HTMLElement,
  messages: string[],
): void {
  // Live validation re-renders a flagged field on every keystroke, and its
  // error can change kind as it is typed into (blank, then malformed). A
  // second list would reuse the first one's id, so the control would go on
  // being described by the stale message.
  clearFieldErrors(wrapper);

  wrapper.classList.add('formable-field--error');

  // A fieldset field (radio, checkboxes, table, name, address) describes the
  // whole group from the fieldset, matching `_field.twig`'s own shape - the
  // individual options only get `aria-invalid`. A plain field has no
  // fieldset, so the control itself is both.
  const fieldset = wrapper.querySelector<HTMLElement>('fieldset');
  const controls = Array.from(
    wrapper.querySelectorAll<HTMLElement>('input, select, textarea'),
  );
  const describedTarget = fieldset ?? controls[0];
  const controlId = describedTarget?.id ?? '';
  const errorsId = controlId ? `${controlId}-errors` : '';

  const list = document.createElement('div');
  list.className = 'formable-field__errors';
  list.dataset.formableFieldErrors = '';

  if (errorsId) {
    list.id = errorsId;
  }

  messages.forEach((message) => {
    const paragraph = document.createElement('p');
    paragraph.className = 'formable-field__error';
    paragraph.textContent = message;
    list.appendChild(paragraph);
  });

  wrapper.appendChild(list);

  controls.forEach((control) => control.setAttribute('aria-invalid', 'true'));

  if (describedTarget && errorsId) {
    const describedBy = (describedTarget.getAttribute('aria-describedby') ?? '')
      .split(' ')
      .filter((id) => id !== '' && id !== errorsId);
    describedBy.push(errorsId);
    describedTarget.setAttribute('aria-describedby', describedBy.join(' '));
  }
}

/** Removes whatever `renderFieldErrors()` (or the server render) left behind on one field. */
export function clearFieldErrors(wrapper: HTMLElement): void {
  wrapper.classList.remove('formable-field--error');

  const errorNodes = Array.from(
    wrapper.querySelectorAll<HTMLElement>('[data-formable-field-errors]'),
  );
  const errorIds = new Set(
    errorNodes.map((el) => el.id).filter((id) => id !== ''),
  );

  errorNodes.forEach((el) => el.remove());

  wrapper.querySelectorAll('[aria-invalid="true"]').forEach((control) => {
    control.removeAttribute('aria-invalid');
  });

  wrapper.querySelectorAll<HTMLElement>('[aria-describedby]').forEach((el) => {
    const describedBy = (el.getAttribute('aria-describedby') ?? '')
      .split(' ')
      .filter((id) => id !== '' && !errorIds.has(id));

    if (describedBy.length) {
      el.setAttribute('aria-describedby', describedBy.join(' '));
    } else {
      el.removeAttribute('aria-describedby');
    }
  });
}
