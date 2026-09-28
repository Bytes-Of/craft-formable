/**
 * Front-end enhancement layer for rendered Formable forms.
 *
 * Vanilla TypeScript, no framework: a site visitor shouldn't download one
 * because a page happens to carry a form. Everything here is an enhancement -
 * a form whose JS fails to load still submits, validates and reports errors
 * through the normal page-reload path.
 *
 * Public surface (see docs/api-stability.md): `window.Formable.init()`,
 * `window.FormableForm.forHandle()`, the `formable:*` DOM events and the
 * `data-formable-*` attributes the docs name. Everything else in this bundle,
 * and every other module beside it, is internal.
 */

import { renderCaptchas } from './captcha';
import { ConditionalFields } from './conditions-dom';
import { applyEnhancers, registerEnhancer } from './enhancers';
import { clearFieldErrors, renderFieldErrors } from './field-errors';
import {
  enhanceFileUploads,
  reportUploadProgress,
  resetUploadProgress,
} from './file-upload';
import { enhanceMultiSelects } from './multi-select';
import { scrollBehavior } from './scroll';
import { enhanceSignatures } from './signature';
import { renderStyleVars } from './style-vars';
import { enhanceTables } from './table';
import { InlineValidation } from './validation-dom';

// Registered once, at module load. Anything that makes a field's markup
// interactive belongs here rather than being called by name from `init()` and
// `swapStep()` - see the contract in `enhancers.ts`.
registerEnhancer(renderCaptchas);
registerEnhancer(enhanceSignatures);
registerEnhancer(enhanceTables);
registerEnhancer(enhanceFileUploads);
registerEnhancer(enhanceMultiSelects);
registerEnhancer(renderStyleVars);

interface SubmitResponse {
  success: boolean;
  message?: string;
  successDetails?: string | null;
  restartLabel?: string | null;
  redirect?: string | null;
  submissionId?: number | null;
  errors?: Record<string, string[]>;
  formErrors?: Record<string, string[]>;
  /** Multi-page navigation: the step just moved to, and its markup. */
  page?: number;
  review?: boolean;
  html?: string | null;
  /** Save-and-resume: whether the progress was stored. */
  saved?: boolean;
  /** The form shut between load and submit - disabled or past its window. */
  closed?: boolean;
}

const FIELD_SELECTOR = '[data-formable-field]';
const STEP_SELECTOR = '[data-formable-step]';

/**
 * @internal Only `FormableForm.forHandle()` is public; the rest of the class
 * is the enhancement's own machinery.
 */
export class FormableForm {
  private readonly formElement: HTMLFormElement;
  private readonly method: string;
  private readonly conditionalFields: ConditionalFields;
  private readonly inlineValidation: InlineValidation;
  private isSubmitting = false;
  private hasWarnedMissingSummary = false;

  /**
   * Takes the element rather than a handle so the fields can be readonly -
   * a constructor that bails out halfway leaves them unassigned.
   */
  constructor(formElement: HTMLFormElement) {
    this.formElement = formElement;
    this.method = formElement.dataset.formableMethod ?? 'page';
    this.conditionalFields = new ConditionalFields(formElement);
    this.inlineValidation = new InlineValidation(formElement);
    this.init();
  }

  /** @api */
  static forHandle(formHandle: string): FormableForm | null {
    const form = document.querySelector<HTMLFormElement>(
      `form[data-formable-handle="${formHandle}"]`,
    );

    if (!form) {
      console.warn(`[Formable] Form with handle "${formHandle}" not found`);

      return null;
    }

    return new FormableForm(form);
  }

  private init(): void {
    // Marked as enhanced so CSS can style states that only exist with JS, and
    // so a second initialization pass skips this form.
    this.formElement.dataset.formableEnhanced = 'true';

    // The validation gate runs for both submit methods - preventing a native
    // page submit on a client-catchable error saves the round trip exactly
    // as much as it does for AJAX. It's checked first, so an AJAX submit that
    // fails it never reaches `handleSubmit()` at all.
    this.formElement.addEventListener('submit', (e) =>
      this.handleSubmitAttempt(e),
    );

    // Conditional logic and inline validation are enhancements the non-AJAX
    // form benefits from too, so both are wired up regardless of submit
    // method. (The upload-preview list and everything else field-level runs
    // through `applyEnhancers` below.)
    this.conditionalFields.attach();
    this.inlineValidation.attach();

    // Prove JavaScript ran, for the optional JS spam check: the field is empty
    // in the markup and only ever gets a value here.
    this.stampJsToken();

    // Enhance whatever is already in the markup - a single-page form, or the
    // step a multi-page one landed on.
    applyEnhancers(this.formElement);

    // Whichever notice the page-reload path rendered - a failed validation or
    // a completed submission - gets focus the same way `handleSuccess()` and
    // `handleErrors()` do for the AJAX path below. The summary wins if both
    // are somehow present, matching how it's checked first here.
    if (!this.focusSummary()) {
      this.focusSuccess();
    }
  }

  /**
   * Moves focus to the error summary whenever one is showing - both error
   * paths converge here, and neither carries `role="alert"`: on the
   * page-reload path the summary is already sitting in the markup at load,
   * where an alert region announces nothing, and on the AJAX path this runs
   * right after `showSummary()` builds it, so focus - not the role - is what
   * reaches a screen reader either way. The summary carries the message and a
   * link to every failing field, and its `tabindex="-1"` is there for exactly
   * this - the same move `swapStep()` makes for a new step's heading.
   *
   * Returns whether it actually moved focus, so `init()` can fall back to the
   * success notice only when there's no summary to compete with it.
   */
  private focusSummary(): boolean {
    const summary = this.getSummaryElement();

    if (summary && !summary.hidden) {
      summary.focus();

      return true;
    }

    return false;
  }

  /**
   * Moves focus to the success notice on the page-reload path, the one place
   * it's rendered already in place rather than swapped in by `handleSuccess()`
   * (which focuses it itself after building it). It sits outside `<form>` -
   * a completed submission replaces the form, it doesn't live alongside it -
   * so this looks in the form's parent rather than the form itself.
   */
  private focusSuccess(): void {
    const success = this.formElement.parentElement?.querySelector<HTMLElement>(
      '[data-formable-success]',
    );

    success?.focus();
  }

  /**
   * Writes a value into the JS-token field, if the form carries one. A bot that
   * posts the form without loading the page leaves it empty.
   */
  private stampJsToken(): void {
    const token = this.formElement.querySelector<HTMLInputElement>(
      '[data-formable-js-token]',
    );

    if (token) {
      token.value = '1';
    }
  }

  /**
   * The one `submit` listener bound regardless of method - it gates on
   * client-side validation first, then either hands off to `handleSubmit()`
   * (AJAX) or, for a plain page submit, simply lets the browser's own
   * navigation continue now that nothing is left to catch.
   *
   * Back and Save & resume never validate server-side either
   * (`PageFlow::back()` and `save()`), so inline validation skips straight to
   * that path for them - otherwise a blank required field on the current page
   * would trap a visitor who is trying to leave it.
   */
  private handleSubmitAttempt(e: Event): void {
    const submitter = (e as SubmitEvent).submitter;
    const skipsValidation = submitter?.matches(
      '[data-formable-back], [data-formable-save-button]',
    );

    if (!skipsValidation) {
      const errors = this.inlineValidation.validateAll();

      if (Object.keys(errors).length > 0) {
        e.preventDefault();
        this.showSummary(this.getErrorMessage(), errors);
        this.focusSummary();

        return;
      }
    }

    if (this.method === 'ajax') {
      void this.handleSubmit(e);
    }
  }

  /**
   * The same message a server-side validation failure would show, carried
   * over in `data-formable-error-message` so a client-only failure reads
   * identically - both are the form's own configured `errorMessage` setting.
   * The fallback only matters for markup that predates this attribute (a
   * hand-written template override that renders its own `<form>`).
   */
  private getErrorMessage(): string {
    return (
      this.formElement.dataset.formableErrorMessage ??
      'There was a problem with your submission. Please check the fields below.'
    );
  }

  private async handleSubmit(e: Event): Promise<void> {
    e.preventDefault();

    // Double-submit is the single most common way to get duplicate rows in
    // the table, and a slow connection is exactly when people click twice.
    if (this.isSubmitting) {
      return;
    }

    // FormData omits the button that triggered the submit, but the whole
    // multi-page protocol turns on *which* button was pressed - so the
    // submitter's name/value is added back by hand.
    const submitter = (e as SubmitEvent).submitter as HTMLButtonElement | null;
    const body = new FormData(this.formElement);

    if (submitter?.name) {
      body.append(submitter.name, submitter.value);
    }

    resetUploadProgress(this.formElement);
    this.setSubmitting(true);

    try {
      const result = await this.post(body);

      this.route(result);
    } catch (error) {
      // A network failure is not a validation failure - say so rather than
      // leaving the submitter staring at a button that did nothing.
      this.showSummary(
        this.formElement.dataset.formableNetworkError ??
          'Your submission could not be sent. Please check your connection and try again.',
      );
      // Land focus on the summary, the same as `handleErrors()` - it's the
      // only signal on any error path now that none of them sets
      // `role="alert"`.
      this.focusSummary();
      // The form itself is still in the document here - nothing in this
      // branch replaces it - so dispatching on it reaches `document` fine.
      this.dispatch(this.formElement, 'formable:error', { success: false });
      console.error('[Formable] Submission failed', error);
    } finally {
      this.setSubmitting(false);
    }
  }

  /**
   * `fetch` has no way to observe how much of the request body has gone out -
   * only `XMLHttpRequest`'s `upload` object fires `progress` - so this reaches
   * for it instead, purely for that event; everything else here (headers,
   * same-origin credentials, JSON in, JSON out) matches what `fetch` did.
   * `reportUploadProgress` turns the one aggregate byte count into each
   * file-upload field's own per-file bars.
   */
  private post(body: FormData): Promise<SubmitResponse> {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', this.formElement.action || window.location.href);
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

      xhr.upload.addEventListener('progress', (event) => {
        if (event.lengthComputable) {
          reportUploadProgress(this.formElement, event.loaded, event.total);
        }
      });

      xhr.addEventListener('load', () => {
        try {
          resolve(JSON.parse(xhr.responseText) as SubmitResponse);
        } catch (error) {
          reject(error);
        }
      });

      xhr.addEventListener('error', () => reject(new Error('Network error')));

      xhr.send(body);
    });
  }

  /**
   * Sends a response to the right handler by its shape.
   *
   * The server reports the *outcome*, not the request - a validation failure
   * on a `next` and on a final submit look identical, and both want the same
   * error rendering - so the branching keys off what came back, not off which
   * button was pressed.
   *
   * Each terminal branch (closed, error, saved, success) dispatches its own
   * `formable:*` event once it knows which element the outcome landed on -
   * `handleSuccess()`, `handleSaved()` and `handleClosed()` all replace
   * `this.formElement` with a new node, so dispatching has to target whichever
   * element is actually in the document afterwards, or the event has nowhere
   * to bubble to. A page-to-page navigation (`swapStep()`) isn't a terminal
   * outcome, so it fires none of them - a "close the modal on success"
   * listener would otherwise fire on every `Next` click, not just completion.
   */
  private route(result: SubmitResponse): void {
    // A form that shut between load and submit is replaced by its closed
    // message, the same as the page-reload path - there's nothing to correct
    // and re-send, so it isn't treated as a validation error.
    if (result.closed) {
      this.handleClosed(result);

      return;
    }

    if (!result.success) {
      this.handleErrors(result);

      return;
    }

    if (result.saved) {
      this.handleSaved(result);

      return;
    }

    // A navigation response carries the next step's markup; a completion
    // doesn't. That's the one reliable signal that distinguishes "moved to
    // another page" from "the form is done".
    if (typeof result.page === 'number') {
      this.swapStep(result);

      return;
    }

    this.handleSuccess(result);
  }

  /**
   * Replaces the current step with the one the server rendered, then restores
   * the enhancements that were bound to the old markup.
   */
  private swapStep(result: SubmitResponse): void {
    const container =
      this.formElement.querySelector<HTMLElement>(STEP_SELECTOR);

    if (!container || result.html == null) {
      return;
    }

    this.clearErrors();
    container.innerHTML = result.html;

    // The new markup has its own conditional fields; re-evaluating picks them
    // up (the listeners themselves live on the form, so they survive).
    this.conditionalFields.apply();

    // Everything else bound to markup - a captcha on the final step, a
    // signature pad, a table's row controls - arrived over a fetch and has
    // never been enhanced. Running the whole registry rather than naming each
    // one is what keeps a newly added enhancer from being forgotten here.
    applyEnhancers(container);

    this.announceProgress(container);

    // Focus and scroll to the top of the new step, so a keyboard or screen
    // reader user lands on it rather than being left where the old page was.
    // A page can render with no label at all (`_page.twig`'s heading is
    // conditional on `page.label`), in which case the step container itself
    // is the fallback target - the old code left focus on `<body>` here.
    const heading = container.querySelector<HTMLElement>(
      '.formable-page__label, .formable-review__heading, [data-formable-progress-label]',
    );
    const focusTarget = heading ?? container;

    focusTarget.setAttribute('tabindex', '-1');
    // `focus()` scrolls the target into view on its own; without
    // `preventScroll` that scroll and the explicit `scrollIntoView()` below
    // fire back to back; wrapping the same content in a sticky-header page,
    // that's a visible double jump rather than one smooth move.
    focusTarget.focus({ preventScroll: true });

    container.scrollIntoView({ behavior: scrollBehavior(), block: 'start' });
  }

  /**
   * Writes the new step's progress label into the live region that sits
   * outside the swappable step in `form.twig`. The visible label just swapped
   * into `container` can't announce itself - it's brand new to the page, and
   * a live region only announces content that changes after it's already
   * there. This one has been sitting on the page since load, so mutating its
   * text is what actually reaches a screen reader.
   */
  private announceProgress(container: HTMLElement): void {
    const announcer = this.formElement.querySelector<HTMLElement>(
      '[data-formable-progress-announcer]',
    );

    if (!announcer) {
      return;
    }

    const label = container.querySelector<HTMLElement>(
      '[data-formable-progress-label]',
    );

    announcer.textContent = label?.textContent?.trim() ?? '';
  }

  private handleSaved(result: SubmitResponse): void {
    // Progress stored and the email sent: replace the form with the
    // confirmation, matching what a completed submission does - the submitter
    // is done with this tab.
    const notice = FormableForm.buildSuccessNotice(result.message ?? '');

    this.formElement.replaceWith(notice);
    // Dispatched on `notice`, not `this.formElement` - the form was just
    // detached by `replaceWith()` above, and an event fired on a detached
    // node has nowhere to bubble to. `notice` sits in the document at the
    // form's old position, so `document` still hears it.
    this.dispatch(notice, 'formable:saved', result);
    notice.focus();
  }

  private handleSuccess(result: SubmitResponse): void {
    if (result.redirect) {
      // Dispatched before navigating away - the form is still in the
      // document at this point, but won't be for long once the browser
      // starts unloading the page.
      this.dispatch(this.formElement, 'formable:success', result);
      window.location.assign(result.redirect);

      return;
    }

    this.clearErrors();

    const success = FormableForm.buildSuccessNotice(result.message ?? '', {
      details: result.successDetails,
      restartLabel: result.restartLabel,
    });

    // Replacing the form (rather than just prepending a message) keeps a
    // submitted form from being edited and re-sent, matching what the
    // page-reload path does.
    this.formElement.replaceWith(success);
    this.dispatch(success, 'formable:success', result);
    success.focus();
  }

  /**
   * Builds the success notice `handleSuccess()` and `handleSaved()` both
   * replace the form with - icon plus a content column, mirroring
   * `form.twig`'s `successMessage` markup exactly. The message is the
   * heading: it's already the one line of copy this state always has, so
   * there's no second, generic "Success!" string to keep in step with the
   * server-rendered path. `details` and `restartLabel` are only ever passed
   * by `handleSuccess()` - a save isn't a completion, so `handleSaved()`
   * leaves them unset and "submit another response" never shows for it.
   */
  private static buildSuccessNotice(
    message: string,
    {
      details,
      restartLabel,
    }: { details?: string | null; restartLabel?: string | null } = {},
  ): HTMLElement {
    const notice = document.createElement('div');
    notice.className = 'formable-success';
    notice.setAttribute('role', 'status');
    notice.setAttribute('tabindex', '-1');
    notice.dataset.formableSuccess = '';
    notice.insertAdjacentHTML(
      'afterbegin',
      '<svg class="formable-success__icon" viewBox="0 0 20 20" fill="none" aria-hidden="true" focusable="false">' +
        '<circle cx="10" cy="10" r="8.5" stroke="currentColor" stroke-width="1.5"/>' +
        '<path d="M6 10.2l2.6 2.6L14.2 7" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>' +
        '</svg>',
    );

    const content = document.createElement('div');
    content.className = 'formable-success__content';

    const heading = document.createElement('h2');
    heading.className = 'formable-success__heading';
    heading.textContent = message;
    content.appendChild(heading);

    if (details) {
      const detailsEl = document.createElement('p');
      detailsEl.className = 'formable-success__details';
      detailsEl.textContent = details;
      content.appendChild(detailsEl);
    }

    if (restartLabel) {
      // A full navigation, not a DOM rebuild - the same reasoning as the
      // server-rendered restart link in `form.twig`: it always arrives with
      // a clean CSRF token and honeypot timestamp.
      const restart = document.createElement('a');
      restart.className = 'formable-success__restart';
      restart.href = window.location.href;
      restart.textContent = restartLabel;
      content.appendChild(restart);
    }

    notice.appendChild(content);

    return notice;
  }

  private handleClosed(result: SubmitResponse): void {
    this.clearErrors();

    const closed = document.createElement('div');
    closed.className = 'formable-closed';
    closed.setAttribute('role', 'status');
    closed.setAttribute('tabindex', '-1');
    closed.dataset.formableClosed = '';
    closed.textContent = result.message ?? '';

    // Replaced rather than annotated, mirroring the server-rendered `_closed`
    // partial the non-JS path lands on - a shut form leaves nothing to edit.
    this.formElement.replaceWith(closed);
    this.dispatch(closed, 'formable:closed', result);
    closed.focus();
  }

  private handleErrors(result: SubmitResponse): void {
    this.clearErrors();

    const errors = result.errors ?? {};

    Object.entries(errors).forEach(([handle, messages]) => {
      const wrapper = this.formElement.querySelector<HTMLElement>(
        `[data-formable-field="${CSS.escape(handle)}"]`,
      );

      if (wrapper) {
        renderFieldErrors(wrapper, messages);
      }
    });

    this.showSummary(result.message ?? '', errors);

    // Land focus on the summary, the same place the page-reload path puts it:
    // it names every failing field and its links are the route to each one.
    // Without a focus move, an AJAX error is silent for anyone not looking at
    // the top of the form.
    this.focusSummary();
    // A validation failure never replaces the form, so it's still the
    // element that was there before `route()` was called.
    this.dispatch(this.formElement, 'formable:error', result);
  }

  /**
   * Clears whatever `handleErrors()` or `InlineValidation` left behind on
   * every field, plus the summary. Per-field markup and wiring live in
   * `field-errors.ts`, shared with the client-only validation path so a
   * field looks and announces identically no matter which one caught it.
   */
  private clearErrors(): void {
    this.formElement
      .querySelectorAll<HTMLElement>(FIELD_SELECTOR)
      .forEach((wrapper) => {
        clearFieldErrors(wrapper);
      });

    const summary = this.getSummaryElement();

    if (summary) {
      const messageEl = summary.querySelector<HTMLElement>(
        '.formable-form__errors-message',
      );

      if (messageEl) {
        messageEl.textContent = '';
      }

      summary.querySelector('.formable-form__errors-list')?.remove();
      summary.hidden = true;
    }
  }

  /**
   * Fills in the error summary - the message, plus a link to each failing
   * field. `form.twig` builds the same `<ul>` of anchors server-side, from
   * the same field data, so the summary reads identically whichever path
   * produced it.
   */
  private showSummary(
    message: string,
    errors: Record<string, string[]> = {},
  ): void {
    const summary = this.getSummaryElement();

    if (!summary) {
      this.warnMissingSummary();

      return;
    }

    if (message === '') {
      return;
    }

    this.getSummaryMessageElement(summary).textContent = message;

    summary.querySelector('.formable-form__errors-list')?.remove();

    const list = this.buildErrorSummaryList(errors);

    if (list) {
      summary.appendChild(list);
    }

    summary.hidden = false;
  }

  /**
   * The summary's message paragraph, created if a template override renders
   * the summary container without one.
   */
  private getSummaryMessageElement(summary: HTMLElement): HTMLElement {
    const existing = summary.querySelector<HTMLElement>(
      '.formable-form__errors-message',
    );

    if (existing) {
      return existing;
    }

    const messageEl = document.createElement('p');
    messageEl.className = 'formable-form__errors-message';
    summary.insertBefore(messageEl, summary.firstChild);

    return messageEl;
  }

  /**
   * One `<li><a></a></li>` per failing field still present in the DOM,
   * pointing at the same anchor `_field.twig` gives it - the input's own
   * `id`, or the `<fieldset>`'s for a grouped field. A handle with no
   * matching field in this step (shouldn't happen; the server only ever
   * reports errors for the step it rendered) is silently skipped rather than
   * linking nowhere.
   */
  private buildErrorSummaryList(
    errors: Record<string, string[]>,
  ): HTMLElement | null {
    const items = Object.keys(errors)
      .map((handle) => this.buildErrorSummaryItem(handle))
      .filter((item): item is HTMLLIElement => item !== null);

    if (items.length === 0) {
      return null;
    }

    const list = document.createElement('ul');
    list.className = 'formable-form__errors-list';
    items.forEach((item) => list.appendChild(item));

    return list;
  }

  private buildErrorSummaryItem(handle: string): HTMLLIElement | null {
    const wrapper = this.formElement.querySelector<HTMLElement>(
      `[data-formable-field="${CSS.escape(handle)}"]`,
    );
    const target = wrapper?.querySelector<HTMLElement>(
      'fieldset, input, select, textarea',
    );

    if (!wrapper || !target?.id) {
      return null;
    }

    const labelEl = wrapper.querySelector<HTMLElement>(
      '.formable-field__label, .formable-field__legend',
    );
    // The label's first child is the field label text itself - the required
    // asterisk (if any) is a sibling `<span>` after it, so this reads the
    // same string `field.label` would render without the marker along.
    const label = labelEl?.firstChild?.textContent?.trim() || handle;

    const item = document.createElement('li');
    const link = document.createElement('a');
    link.href = `#${target.id}`;
    link.textContent = label;
    item.appendChild(link);

    return item;
  }

  private getSummaryElement(): HTMLElement | null {
    return this.formElement.querySelector<HTMLElement>(
      '[data-formable-error-summary]',
    );
  }

  /**
   * `showSummary()` copes with an override missing the message paragraph
   * (`getSummaryMessageElement()` builds one), but there's no recovering from
   * a `[data-formable-error-summary]` container that isn't there at all - an
   * error has nowhere to go and nothing tells the site builder why. Logged
   * once per form instance rather than on every failed submit.
   */
  private warnMissingSummary(): void {
    if (this.hasWarnedMissingSummary) {
      return;
    }

    this.hasWarnedMissingSummary = true;

    console.warn(
      '[Formable] No [data-formable-error-summary] element found - a template override may have dropped it, so validation errors have nowhere to be reported.',
    );
  }

  private setSubmitting(isSubmitting: boolean): void {
    this.isSubmitting = isSubmitting;
    this.formElement.classList.toggle(
      'formable-form--submitting',
      isSubmitting,
    );

    // Real `disabled` would drop focus to `<body>` on whichever button was
    // just pressed - the one place in the whole flow keyboard focus most
    // needs to survive. The button stays enabled and focusable;
    // `handleSubmit()`'s `isSubmitting` guard is what actually blocks the
    // repeat request, and `aria-disabled` tells assistive tech the same thing
    // the (removed) `disabled` attribute used to.
    this.formElement
      .querySelectorAll<HTMLButtonElement>(
        '[data-formable-submit], [data-formable-next], [data-formable-back], [data-formable-save-button]',
      )
      .forEach((button) => {
        if (isSubmitting) {
          button.setAttribute('aria-disabled', 'true');
          button.setAttribute('aria-busy', 'true');
        } else {
          button.removeAttribute('aria-disabled');
          button.removeAttribute('aria-busy');
        }
      });
  }

  /**
   * `target` is explicit rather than always `this.formElement` - by the time
   * `handleSuccess()`, `handleSaved()` and `handleClosed()` are ready to
   * dispatch, `this.formElement` has usually just been replaced and detached,
   * and a `CustomEvent` fired on a detached node has no ancestor to bubble
   * to. Each caller passes whichever node is actually in the document.
   */
  private dispatch(
    target: HTMLElement,
    name: string,
    detail: SubmitResponse | Record<string, unknown>,
  ): void {
    target.dispatchEvent(
      new CustomEvent(name, { detail, bubbles: true, cancelable: false }),
    );
  }
}

declare global {
  interface Window {
    FormableForm?: typeof FormableForm;
    Formable?: typeof Formable;
  }
}

/**
 * Enhances every un-enhanced Formable form under `root`. `root` defaults to
 * `document` for the load-time pass below; `Formable.init()` exposes the same
 * function so a form that arrives after load - a Sprig/htmx partial, a form
 * injected into a modal - can be enhanced without a full page reload. Already
 * running against `document` even when called with a narrower `root` would
 * silently re-scan (and skip, via `:not([data-formable-enhanced])`) every
 * form already on the page, which is harmless but pointless - passing the
 * inserted subtree keeps the scan scoped to what actually changed.
 */
function initForms(root: ParentNode = document): void {
  root
    .querySelectorAll<HTMLFormElement>(
      'form[data-formable-handle]:not([data-formable-enhanced])',
    )
    .forEach((form) => {
      new FormableForm(form);
    });
}

// The bundle is deferred, so the document may already be parsed by the time
// it runs - waiting on DOMContentLoaded alone would miss that case.
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => initForms());
} else {
  initForms();
}

/**
 * The documented public entry point (see "JavaScript lifecycle" in
 * `docs/templating.md`) for enhancing a form that wasn't on the page at load
 * time. `FormableForm` itself stays on `window` too, for code that wants a
 * specific instance (`FormableForm.forHandle()`) rather than "enhance
 * whatever's new".
 *
 * @api
 */
export const Formable = {
  init(root?: ParentNode): void {
    initForms(root);
  },
};

window.FormableForm = FormableForm;
window.Formable = Formable;
