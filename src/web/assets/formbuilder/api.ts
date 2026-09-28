/**
 * Talking to Craft from the builder.
 *
 * Craft's controller actions want a CSRF token and an `Accept: application/json`
 * header - without the latter the controller's `requireAcceptsJson()` rejects
 * the request before it does any work.
 */

import { t } from './helpers';
import type { Page, Translations } from './types';

let csrf = { name: 'CRAFT_CSRF_TOKEN', token: '' };

export function configureCsrf(name: string, token: string): void {
  csrf = { name, token };
}

/**
 * Craft's own URL builder, which accounts for `actionTrigger`,
 * `omitScriptNameInUrls` and subfolder installs. It's always present in the
 * CP via CpAsset; the fallback only matters if this bundle is ever loaded
 * outside it.
 */
function actionUrl(action: string): string {
  const craft = (
    window as unknown as { Craft?: { getActionUrl?: (a: string) => string } }
  ).Craft;

  return craft?.getActionUrl?.(action) ?? `/index.php?p=actions/${action}`;
}

export class ApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

/**
 * Posts JSON to a Craft controller action.
 *
 * Payloads are sent as JSON rather than form-encoded so nested layout arrays
 * survive intact - PHP's form parsing mangles deep structures.
 */
export async function post<T>(
  action: string,
  payload: Record<string, unknown>,
): Promise<T> {
  const response = await fetch(actionUrl(action), {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      'X-Requested-With': 'XMLHttpRequest',
      'X-CSRF-Token': csrf.token,
    },
    body: JSON.stringify({ ...payload, [csrf.name]: csrf.token }),
  });

  if (!response.ok) {
    let message = t('Request failed ({status})', { status: response.status });

    try {
      const body = await response.json();
      message = body?.error ?? body?.message ?? message;
    } catch {
      // Craft returns an HTML error page when debug mode is on; the status
      // code is all we can salvage.
    }

    throw new ApiError(message, response.status);
  }

  return (await response.json()) as T;
}

/**
 * Opens the builder's current working layout in a new tab, rendered exactly
 * as it will look on the front end.
 *
 * A real POST through a hidden, immediately-submitted `<form target="_blank">`
 * rather than `fetch()` plus `window.open()`: the layout can be large enough
 * to want a request body, not a query string, and submitting a form is what
 * lets the browser open the new tab synchronously inside the click handler -
 * `window.open()` called after an `await` is what pop-up blockers actually
 * catch.
 */
export function openPreview(action: string, payload: PreviewPayload): void {
  const form = document.createElement('form');
  form.method = 'post';
  form.action = actionUrl(action);
  form.target = '_blank';
  form.style.display = 'none';

  const fields: Record<string, string> = {
    [csrf.name]: csrf.token,
    title: payload.title,
    pages: JSON.stringify(payload.pages),
    settings: JSON.stringify(payload.settings),
    translations: JSON.stringify(payload.translations),
  };

  for (const [name, value] of Object.entries(fields)) {
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = name;
    input.value = value;
    form.appendChild(input);
  }

  document.body.appendChild(form);
  form.submit();
  form.remove();
}

export interface PreviewPayload {
  title: string;
  pages: Page[];
  settings: Record<string, unknown>;
  translations: Translations;
}
