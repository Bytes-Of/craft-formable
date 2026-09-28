/**
 * Inline client-side validation, wired to the DOM.
 *
 * The decision of whether a value passes is the shared engine's
 * (`shared/validation.ts`, the same code `helpers/Validation.php` is tested
 * against); this file only reads the current value out of one control and
 * applies the verdict to the page, the same split `conditions-dom.ts` makes
 * for visibility. A field the conditions engine has hidden is skipped here
 * too - its value is about to be blanked server-side regardless of what's
 * still sitting in the (disabled) control.
 */

import { clearFieldErrors, renderFieldErrors } from './field-errors';
import {
  type ValidationRuleCode,
  type ValidationSpec,
  evaluate,
  normalizeSpec,
} from '../shared/validation';

type Control = HTMLInputElement | HTMLSelectElement | HTMLTextAreaElement;

interface ValidatableField {
  handle: string;
  spec: ValidationSpec;
  messages: Partial<Record<ValidationRuleCode, string>>;
  wrapper: HTMLElement;
  control: Control;
}

const CONTROL_SELECTOR =
  'input:not([data-formable-subvalue]), select:not([data-formable-subvalue]), textarea:not([data-formable-subvalue])';

export class InlineValidation {
  private readonly form: HTMLFormElement;

  /** Handles currently showing a client-rendered error, so live typing only re-checks a field already flagged. */
  private readonly invalid = new Set<string>();

  constructor(form: HTMLFormElement) {
    this.form = form;
  }

  /**
   * Binds live re-validation. `blur` doesn't bubble, so it's bound on the
   * capture phase instead - the same reach `input`/`change` get for free.
   */
  attach(): void {
    this.form.addEventListener('blur', (e) => this.handleLiveEvent(e), true);
    this.form.addEventListener('input', (e) => this.handleLiveEvent(e));
    this.form.addEventListener('change', (e) => this.handleLiveEvent(e));
  }

  /**
   * Validates every client-checkable field currently in the form (one page's
   * worth, for a multi-page form - the others aren't in the DOM), rendering
   * or clearing each one's inline errors as it goes.
   *
   * Returns the failures by handle, in the same shape a server error response
   * carries - so the caller can feed it straight into the same summary
   * builder either path uses.
   */
  validateAll(): Record<string, string[]> {
    const errors: Record<string, string[]> = {};

    this.collectFields().forEach((field) => {
      const messages = this.validateField(field);

      if (messages.length > 0) {
        errors[field.handle] = messages;
      }
    });

    return errors;
  }

  private handleLiveEvent(e: Event): void {
    const target = e.target;

    if (!(target instanceof HTMLElement)) {
      return;
    }

    const wrapper = target.closest<HTMLElement>('[data-formable-validation]');

    if (!wrapper) {
      return;
    }

    const field = this.parseField(wrapper);

    if (!field) {
      return;
    }

    // Blur always checks - it's what first tells the submitter a field is
    // wrong. Before that, re-validating on every keystroke of a field nobody
    // has left yet would read as impatient; once a field is flagged, clearing
    // that flag live as they fix it is the point.
    if (e.type !== 'blur' && !this.invalid.has(field.handle)) {
      return;
    }

    this.validateField(field);
  }

  private validateField(field: ValidatableField): string[] {
    if (field.wrapper.hidden || field.control.disabled) {
      clearFieldErrors(field.wrapper);
      this.invalid.delete(field.handle);

      return [];
    }

    const codes = evaluate(field.spec, field.control.value);

    if (codes.length === 0) {
      clearFieldErrors(field.wrapper);
      this.invalid.delete(field.handle);

      return [];
    }

    const messages = codes
      .map((code) => field.messages[code])
      .filter((message): message is string => Boolean(message));

    renderFieldErrors(field.wrapper, messages);
    this.invalid.add(field.handle);

    return messages;
  }

  private collectFields(): ValidatableField[] {
    return Array.from(
      this.form.querySelectorAll<HTMLElement>('[data-formable-validation]'),
    )
      .map((wrapper) => this.parseField(wrapper))
      .filter((field): field is ValidatableField => field !== null);
  }

  private parseField(wrapper: HTMLElement): ValidatableField | null {
    const handle = wrapper.dataset.formableField ?? '';
    const raw = wrapper.dataset.formableValidation;

    if (handle === '' || !raw) {
      return null;
    }

    let parsed: unknown;

    try {
      parsed = JSON.parse(raw);
    } catch {
      // A malformed attribute shouldn't take the field out of the form -
      // treat it as unconstrained, which is the safe (never blocks a
      // submission) default.
      return null;
    }

    const control = wrapper.querySelector<Control>(CONTROL_SELECTOR);

    if (!control) {
      return null;
    }

    const messages =
      typeof parsed === 'object' && parsed !== null && !Array.isArray(parsed)
        ? ((parsed as Record<string, unknown>).messages ?? {})
        : {};

    return {
      handle,
      spec: normalizeSpec(parsed),
      messages: messages as Partial<Record<ValidationRuleCode, string>>,
      wrapper,
      control,
    };
  }
}
