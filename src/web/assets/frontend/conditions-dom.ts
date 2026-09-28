/**
 * Conditional logic, wired to the DOM.
 *
 * The decision of whether a field should show is the shared engine's
 * (`shared/conditions.ts`, the same code the server validates with); this file
 * only reads current values out of the form and applies the verdict to the
 * page. Keeping the two apart is what lets the engine be tested against the PHP
 * side without a browser.
 *
 * A hidden field is also disabled, so its stale value never posts - which
 * mirrors the server blanking a hidden field's value before it validates or
 * stores anything. Without that, a field filled in and then hidden would still
 * arrive in the submission.
 *
 * On a multi-page form only the current page's fields are in the DOM, so a
 * condition naming an earlier page's field has nothing to read there - the
 * value map is seeded from `[data-formable-values]` (`_step.twig`, the same
 * map `Conditions::resolve()` validates server-side) and then overlaid with
 * whatever the current page's own wrappers report, which stays authoritative
 * for what the submitter is typing right now.
 */

import {
  type ResolvableField,
  type ValueMap,
  resolve,
} from '../shared/conditions';

interface ConditionalField {
  handle: string;
  conditions: unknown;
  wrapper: HTMLElement;
}

export class ConditionalFields {
  private readonly form: HTMLFormElement;

  constructor(form: HTMLFormElement) {
    this.form = form;
  }

  /**
   * Binds the live re-evaluation, and runs it once so the initial state is
   * correct even before the submitter touches anything.
   *
   * The listeners live on the form and the fields are re-collected on every
   * pass, so this keeps working after AJAX navigation swaps the current page's
   * markup out from under it - there's no per-field state to go stale.
   */
  attach(): void {
    // `input` covers typing; `change` covers checkboxes, radios and selects,
    // which don't fire `input` consistently across browsers.
    this.form.addEventListener('input', () => this.apply());
    this.form.addEventListener('change', () => this.apply());

    this.apply();
  }

  /** Re-reads the form and shows or hides each conditional field. */
  apply(): void {
    const fields = this.collectFields();

    if (!fields.some((field) => field.conditions != null)) {
      return;
    }

    const values = { ...this.readSeededValues(), ...this.readValues(fields) };

    const resolvable: ResolvableField[] = fields.map((field) => ({
      handle: field.handle,
      conditions: field.conditions,
    }));

    // One page: the current one. Chaining and the blanking of hidden fields'
    // values are the engine's job, so a field hidden because the field it
    // depends on is itself hidden collapses correctly here too.
    const visibility = resolve(
      [{ conditions: null, fields: resolvable }],
      values,
    ).fields;

    fields.forEach((field) => {
      this.setVisible(field, visibility[field.handle] ?? true);
    });
  }

  private setVisible(field: ConditionalField, visible: boolean): void {
    field.wrapper.hidden = !visible;
    field.wrapper.classList.toggle('formable-field--hidden', !visible);

    // Disabled controls are omitted from the submission, which is what keeps a
    // hidden field's value from posting. `data-formable-was-required` remembers
    // the state to restore, so re-showing a field doesn't strip a `required`
    // the author set.
    field.wrapper
      .querySelectorAll<
        HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
      >('input, select, textarea')
      .forEach((control) => {
        control.disabled = !visible;
      });
  }

  private collectFields(): ConditionalField[] {
    return Array.from(
      this.form.querySelectorAll<HTMLElement>('[data-formable-field]'),
    ).map((wrapper) => ({
      handle: wrapper.dataset.formableField ?? '',
      conditions: this.parseConditions(wrapper),
      wrapper,
    }));
  }

  private parseConditions(wrapper: HTMLElement): unknown {
    const raw = wrapper.dataset.formableConditions;

    if (!raw) {
      return null;
    }

    try {
      return JSON.parse(raw);
    } catch {
      // A malformed attribute shouldn't take the field out of the form -
      // treat it as unconditional, which is the safe (visible) default.
      return null;
    }
  }

  /**
   * Values from pages already answered, carried down from the server -
   * `readValues()` below only ever sees what's on the current page, so a rule
   * against a field on an earlier page would otherwise resolve against
   * `undefined` on every page but the one that field lives on.
   */
  private readSeededValues(): ValueMap {
    const raw = this.form.querySelector<HTMLElement>('[data-formable-values]')
      ?.dataset.formableValues;

    if (!raw) {
      return {};
    }

    try {
      return JSON.parse(raw) as ValueMap;
    } catch {
      // Malformed, same treatment as a malformed `data-formable-conditions`:
      // fall back rather than take the field out of the form.
      return {};
    }
  }

  /**
   * Reads the current value of every field, keyed by handle.
   *
   * Values are gathered per wrapper rather than by walking `form.elements`, so
   * a control's handle comes from the field it lives in and never from parsing
   * its `name` - composite fields (name, address) whose inputs are
   * `fields[handle][part]` collapse to one entry per handle.
   *
   * Controls marked `data-formable-subvalue` are skipped: they sit in the
   * wrapper but aren't part of the field's value (an email confirmation box),
   * and counting them would turn a scalar into a two-item array.
   */
  private readValues(fields: ConditionalField[]): ValueMap {
    const values: ValueMap = {};

    fields.forEach((field) => {
      if (field.handle !== '') {
        values[field.handle] = this.readFieldValue(field.wrapper);
      }
    });

    return values;
  }

  private readFieldValue(wrapper: HTMLElement): unknown {
    const controls = Array.from(
      wrapper.querySelectorAll<
        HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement
      >(
        'input:not([data-formable-subvalue]), select:not([data-formable-subvalue]), textarea:not([data-formable-subvalue])',
      ),
    );

    const collected: string[] = [];

    controls.forEach((control) => {
      if (
        control instanceof HTMLInputElement &&
        (control.type === 'checkbox' || control.type === 'radio')
      ) {
        if (control.checked) {
          collected.push(control.value);
        }

        return;
      }

      if (control instanceof HTMLSelectElement && control.multiple) {
        Array.from(control.selectedOptions).forEach((option) =>
          collected.push(option.value),
        );

        return;
      }

      collected.push(control.value);
    });

    if (collected.length === 0) {
      return null;
    }

    return collected.length === 1 ? collected[0] : collected;
  }
}
