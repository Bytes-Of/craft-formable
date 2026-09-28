/**
 * The field-enhancer registry.
 *
 * A few field types render markup that is inert on its own - a signature
 * canvas, a table's "add a row" button. Each of those behaviours lives in its
 * own module exporting one enhancer, and every enhancer is registered here so
 * the AJAX step swap can re-run *all* of them over the new markup instead of
 * naming them one at a time and silently missing the next one added. Shipping a
 * hook in a template with no handler behind it is exactly how the signature and
 * table fields spent a release as dead controls.
 *
 * The contract an enhancer must keep:
 *
 *  - **Idempotent.** It is called once over the whole form on load and again
 *    over each swapped-in step, so it will meet elements it has already
 *    enhanced. The convention is to claim an element with a
 *    `data-formable-<name>-ready` attribute and skip anything carrying it.
 *  - **Progressive.** The markup it enhances already posts something the server
 *    understands; the enhancer improves it and never becomes load-bearing for
 *    a value the visitor could otherwise supply.
 *  - **Contained.** A throw is caught here, so one broken enhancer can't take
 *    the rest of the form's JavaScript with it.
 */

export type FieldEnhancer = (root: ParentNode) => void;

const registry: FieldEnhancer[] = [];

export function registerEnhancer(enhancer: FieldEnhancer): void {
  registry.push(enhancer);
}

/** Runs every registered enhancer over `root`, in registration order. */
export function applyEnhancers(root: ParentNode): void {
  registry.forEach((enhancer) => {
    try {
      enhancer(root);
    } catch (error) {
      console.warn('[Formable] A field enhancement failed', error);
    }
  });
}
