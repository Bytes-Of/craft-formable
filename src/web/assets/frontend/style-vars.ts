/**
 * Applies CSS state that a strict `style-src` Content-Security-Policy would
 * otherwise strip out of an inline `style` attribute.
 *
 * Three spots in the rendered form used to carry a value the server couldn't
 * express as a class: the progress bar's fill width (recomputed on every
 * step), a textarea's row-based `min-height`, and a Table field's
 * author-configured column widths. Without `unsafe-inline`, a browser drops
 * the attribute but leaves the markup otherwise intact - so the fill silently
 * fell back to the track's own full width (every step read 100%), and the
 * textarea/table fell back to whatever their native sizing produces. Setting
 * the same values here through the CSSOM (`element.style.foo = …`) isn't
 * inline style as CSP defines it, so it applies under a policy the markup
 * can't - and without JavaScript at all, every one of these degrades to
 * something merely imprecise rather than wrong: the fill stays at its CSS
 * default while the textual step label carries the real progress, and the
 * textarea/table fall back to the browser's own layout.
 *
 * A fourth case, the brand palette (`repairPalette()` below), is a repair
 * pass rather than the primary path: `Rendering::registerAssets()` already
 * emits the palette as a `<style>` block, which paints with no flash and
 * works without JavaScript, and only needs help here when CSP dropped it.
 */

export function renderStyleVars(root: ParentNode): void {
  root
    .querySelectorAll<HTMLElement>('[data-formable-progress-fill]')
    .forEach((fill) => {
      const percent = fill.dataset.percent;

      if (percent !== undefined) {
        fill.style.width = `${percent}%`;
      }
    });

  root
    .querySelectorAll<HTMLTextAreaElement>('.formable-input--textarea[rows]')
    .forEach((textarea) => {
      textarea.style.setProperty(
        '--formable-textarea-rows',
        String(textarea.rows),
      );
    });

  root.querySelectorAll<HTMLElement>('th[data-width]').forEach((th) => {
    const width = th.dataset.width;

    if (width) {
      th.style.width = width;
    }
  });

  repairPalette(root);
}

/**
 * Repairs a dropped palette `<style>` block through the CSSOM.
 *
 * `root` is the form itself on the load-time pass and a step container
 * (a descendant of the form) after an AJAX navigation - `closest()` finds the
 * form either way, since it matches the starting element too. Nothing to do
 * without `data-formable-palette-vars`: a form with no palette set, or one
 * rendered at a theme level with no paint to receive it, carries neither the
 * attribute nor the registered block (`Rendering::resolvePalette()`).
 *
 * Whether the block already applied is read off one of the palette's own
 * declared properties rather than always `--formable-brand`: a palette that
 * sets only `surface`/`text`/`border` never declares `--formable-brand` at
 * all, and checking a property the palette doesn't set would misread "not
 * set" as "CSP dropped it" and repair a form that never needed it.
 */
function repairPalette(root: ParentNode): void {
  const form =
    root instanceof Element
      ? root.closest<HTMLElement>('form[data-formable-palette-vars]')
      : null;

  if (!form || form.dataset.formablePaletteRepaired !== undefined) {
    return;
  }

  form.dataset.formablePaletteRepaired = 'true';

  const json = form.dataset.formablePaletteVars;

  if (!json) {
    return;
  }

  let declarations: Record<string, string>;

  try {
    declarations = JSON.parse(json) as Record<string, string>;
  } catch {
    return;
  }

  const [sampleProperty] = Object.keys(declarations);

  if (
    !sampleProperty ||
    getComputedStyle(form).getPropertyValue(sampleProperty).trim() !== ''
  ) {
    return;
  }

  Object.entries(declarations).forEach(([property, value]) => {
    form.style.setProperty(property, value);
  });
}
