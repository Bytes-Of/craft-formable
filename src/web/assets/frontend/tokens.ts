/**
 * Refreshes the three request-scoped values a rendered form bakes in - the CSRF
 * token, the signed time token and the flow id - for pages served from a
 * static cache, where every visitor otherwise receives the values that were
 * current when the cache was written.
 *
 * Deliberately not an enhancer: enhancers re-run on `swapStep()`, where
 * rewriting a flow id mid-flow would orphan stored progress, and they only see
 * step markup rather than the shell that holds these inputs.
 */

interface TokenPayload {
  success?: boolean;
  csrfTokenName?: string;
  csrfToken?: string;
  timeToken?: string;
  flowIds?: string[];
}

function writeCsrf(form: HTMLFormElement, name: string, value: string): void {
  const existing = Array.from(
    form.querySelectorAll<HTMLInputElement>('input[type="hidden"]'),
  ).find((input) => input.name === name);

  if (existing) {
    existing.value = value;
    return;
  }

  // Under `asyncCsrfInputs` Craft renders a placeholder element instead of an
  // input, so there is nothing to match by name.
  const placeholder = form.querySelector('craft-csrf-input');

  if (placeholder) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    placeholder.replaceWith(input);
  }
}

/**
 * Only forms the server marked with `data-formable-tokens` take part, so the
 * feature is switched from the server and nothing here decides. A repeat
 * `Formable.init()` hands over only not-yet-enhanced forms, which is what
 * keeps this from firing twice for the same form.
 *
 * On any failure the baked markup is left exactly as rendered.
 */
export async function refreshTokens(forms: HTMLFormElement[]): Promise<void> {
  const targets = forms.filter((form) => form.dataset.formableTokens);

  if (targets.length === 0) {
    return;
  }

  const flowInputs = targets.map((form) =>
    form.querySelector<HTMLInputElement>('input[data-formable-flow]'),
  );
  const flowCount = flowInputs.filter(Boolean).length;

  try {
    const url = new URL(
      targets[0].dataset.formableTokens as string,
      window.location.href,
    );
    url.searchParams.set('flows', String(flowCount));

    const response = await fetch(url.toString(), {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });

    if (!response.ok) {
      throw new Error(`HTTP ${response.status}`);
    }

    const payload = (await response.json()) as TokenPayload;

    if (!payload.success) {
      throw new Error('The server did not return tokens.');
    }

    const flowIds = payload.flowIds ?? [];
    let nextFlow = 0;

    targets.forEach((form, index) => {
      if (payload.csrfTokenName && payload.csrfToken) {
        writeCsrf(form, payload.csrfTokenName, payload.csrfToken);
      }

      const timeField = form.querySelector<HTMLInputElement>(
        '[data-formable-time-token]',
      );

      if (timeField && payload.timeToken) {
        timeField.value = payload.timeToken;
      }

      const flowInput = flowInputs[index];

      if (flowInput && flowIds[nextFlow]) {
        flowInput.value = flowIds[nextFlow++];
        flowInput.removeAttribute('data-formable-flow');
      }
    });
  } catch (error) {
    console.warn('Formable could not refresh the form tokens.', error);
  }
}
