/**
 * Small utilities shared across the builder.
 */

import { nextTick } from 'vue';

/**
 * A handle from a human label, matching how Craft's own HandleGenerator
 * behaves: strip accents, drop anything that isn't alphanumeric, camelCase
 * the rest, and make sure it starts with a letter.
 */
export function camelize(input: string): string {
  const words = input
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .replace(/[^a-zA-Z0-9]+/g, ' ')
    .trim()
    .split(' ')
    .filter(Boolean);

  if (words.length === 0) {
    return '';
  }

  const handle = words
    .map((word, index) =>
      index === 0
        ? word.charAt(0).toLowerCase() + word.slice(1)
        : word.charAt(0).toUpperCase() + word.slice(1),
    )
    .join('');

  // Handles can't start with a digit - Craft's HandleValidator rejects it.
  return /^[0-9]/.test(handle) ? `field${handle}` : handle;
}

export function uuid(): string {
  if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
    return crypto.randomUUID();
  }

  // Craft supports browsers without crypto.randomUUID over plain HTTP, where
  // it's unavailable even in modern engines.
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (char) => {
    const random = (Math.random() * 16) | 0;
    const value = char === 'x' ? random : (random & 0x3) | 0x8;

    return value.toString(16);
  });
}

/** A `{placeholder}` value substituted into a translated string. */
export type TranslationParams = Record<string, string | number>;

interface CraftRuntime {
  t?: (category: string, message: string, params?: TranslationParams) => string;
}

/**
 * Translates a builder string through Craft's own CP runtime.
 *
 * The messages are registered server-side by `FormBuilderAsset`, which reads
 * the generated `strings.php` list, so nothing here has to be kept in sync by
 * hand. `Craft.t` also does the `{placeholder}` interpolation, which is why
 * every string with a variable in it is written as one message rather than
 * concatenated fragments a translator can't reorder.
 */
export function t(message: string, params?: TranslationParams): string {
  const craft = (window as unknown as { Craft?: CraftRuntime }).Craft;

  if (craft?.t) {
    return craft.t('formable', message, params);
  }

  // Outside the CP - unit tests, mainly - fall back to the source string with
  // the same interpolation, so a caller never sees a raw `{placeholder}`.
  return params ? interpolate(message, params) : message;
}

function interpolate(message: string, params: TranslationParams): string {
  return message.replace(/\{(\w+)\}/g, (match, key: string) =>
    key in params ? String(params[key]) : match,
  );
}

/**
 * Refocuses a control after a store action that reorders a keyed `v-for`
 * list - a move up/down/left/right. `vuedraggable`'s own reconciliation of
 * that list doesn't reliably keep the previously-focused node's focus even
 * when the node itself survives the reorder, so rather than guess whether a
 * given move needed it, this always checks after the DOM settles and
 * restores focus whenever it fell back to `<body>`.
 */
export async function restoreFocusAfterMove(
  find: () => HTMLElement | null | undefined,
): Promise<void> {
  await nextTick();

  if (document.activeElement !== document.body) {
    return;
  }

  find()?.focus();
}

/**
 * Deliberately moves focus once the DOM settles, unlike
 * {@link restoreFocusAfterMove}. A merge or a split changes what a
 * reasonable focus target even *is* - the control that was clicked may
 * still exist but now describe a different, less useful action (a "merge"
 * with nothing left to merge into) - so the caller always wants its chosen
 * target focused, not only as a fallback for a lost one.
 */
export async function focusAfterMove(
  find: () => HTMLElement | null | undefined,
): Promise<void> {
  await nextTick();
  find()?.focus();
}

/**
 * Whether a settings value satisfies one `when` clause entry. An array
 * `expected` means "one of" - the actual value only has to match one member,
 * which is how a control can stay visible across several values of the
 * setting it depends on (e.g. a colour override shown for either a light or
 * a dark colour scheme, hidden for auto or unset).
 */
export function matchesWhen(actual: unknown, expected: unknown): boolean {
  if (Array.isArray(expected)) {
    return expected.includes(actual);
  }

  return actual === expected;
}
