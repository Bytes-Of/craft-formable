/**
 * Shared by every enhancer that calls `scrollIntoView()` - `main.ts`'s own
 * step/error-summary navigation and `multi-select.ts`'s popup - so a
 * `prefers-reduced-motion` visitor gets the same instant jump everywhere
 * rather than one module remembering the check and another forgetting it.
 *
 * `scrollIntoView` has no CSS-level reduced-motion equivalent, so the
 * 'smooth' behavior has to be swapped out in script rather than left to the
 * stylesheet.
 */
export function scrollBehavior(): ScrollBehavior {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches
    ? 'auto'
    : 'smooth';
}
