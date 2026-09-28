/**
 * Signature capture.
 *
 * The Signature field renders a `<canvas>`, a clear button and a hidden input;
 * this is what makes them do anything. Drawing writes a PNG data URL into the
 * hidden input, which is the only thing that ever posts - the canvas itself is
 * not a form control, so without this module a required Signature field can
 * never be satisfied.
 *
 * Pointer events rather than mouse + touch pairs: one code path covers mouse,
 * stylus and finger, and pointer capture keeps a stroke tracking even when the
 * pointer leaves the canvas mid-drag.
 *
 * Drawing is a pointer gesture, so on its own the field is unreachable by
 * keyboard, screen-reader, switch or voice input - a WCAG 2.1.1 (Level A)
 * failure on a field that can be marked required. The visible text input is
 * the alternative: a typed name is rendered to the same canvas in a script
 * face and committed as the same PNG data URL, so the two routes are
 * indistinguishable to the server and every downstream consumer.
 */

const SELECTOR =
  '[data-formable-signature]:not([data-formable-signature-ready])';

/** Stroke width in CSS pixels. Fixed: a signature is not a drawing tool. */
const LINE_WIDTH = 2;

export function enhanceSignatures(root: ParentNode): void {
  root
    .querySelectorAll<HTMLElement>(SELECTOR)
    .forEach((wrapper) => setUp(wrapper));
}

function setUp(wrapper: HTMLElement): void {
  const canvas = wrapper.querySelector('canvas');
  const input = wrapper.querySelector<HTMLInputElement>('input[type="hidden"]');
  const context = canvas?.getContext('2d') ?? null;

  // No 2d context means no signature is possible at all (a very old browser, or
  // one with canvas switched off). Left unclaimed and untouched: the field
  // degrades to what it already is, an empty hidden input.
  if (!canvas || !input || !context) {
    return;
  }

  wrapper.dataset.formableSignatureReady = 'true';

  const clearButton = wrapper.querySelector<HTMLButtonElement>(
    '[data-formable-signature-clear]',
  );
  const typedInput = wrapper.querySelector<HTMLInputElement>(
    '[data-formable-signature-typed]',
  );
  const configuredWidth = Number(wrapper.dataset.width) || canvas.width || 400;
  const configuredHeight =
    Number(wrapper.dataset.height) || canvas.height || 200;
  const penColor = wrapper.dataset.penColor || '#000000';
  const backgroundColor = wrapper.dataset.backgroundColor || '#ffffff';
  const signedLabel = wrapper.dataset.signedLabel ?? 'Signature captured';
  const clearedLabel = wrapper.dataset.clearedLabel ?? 'Signature cleared';

  const notice = document.createElement('p');
  notice.className = 'formable-field__notice';
  notice.setAttribute('role', 'status');
  notice.setAttribute('aria-live', 'polite');
  notice.hidden = true;
  wrapper.appendChild(notice);

  // The CSS box itself is sized declaratively, by the reset stylesheet, not
  // by this script: `inline-size: 100%` with a cap at the field's configured
  // width, and an `aspect-ratio` so a container narrower than that width
  // shrinks the box without distorting it - the two custom properties below
  // are what feed that rule the field's own configured dimensions. Every
  // coordinate below is in CSS pixels of whatever that box currently
  // measures, tracked by `width`/`height` and kept in sync by the
  // `ResizeObserver` in `resize()`, not the fixed pixel size the field was
  // configured with. See [[frontend-embeddability-review]] chunk 9 (M4).
  canvas.style.setProperty(
    '--formable-signature-width',
    `${configuredWidth}px`,
  );
  canvas.style.setProperty(
    '--formable-signature-ratio',
    `${configuredWidth} / ${configuredHeight}`,
  );
  // Without this, drawing with a finger scrolls the page instead.
  canvas.style.touchAction = 'none';

  let width = 0;
  let height = 0;
  let hasInk = false;
  let isDrawing = false;

  // Tracks the last announced state so a run of pointer-move strokes, or of
  // keystrokes typing a name, only announces the moment signing starts or ends
  // - not every stroke or letter in between.
  let lastAnnouncedSigned: boolean | null = null;

  const paintBackground = (): void => {
    context.save();
    context.fillStyle = backgroundColor;
    context.fillRect(0, 0, width, height);
    context.restore();
  };

  const syncControls = (): void => {
    wrapper.classList.toggle('formable-signature--signed', hasInk);

    if (clearButton) {
      clearButton.disabled = !hasInk;
    }
  };

  /**
   * Writes the canvas back to the hidden input.
   *
   * Fired at the end of a stroke rather than during it: `toDataURL` re-encodes
   * the whole bitmap, which is far too expensive to do per pointer move.
   */
  const commit = (): void => {
    input.value = hasInk ? canvas.toDataURL('image/png') : '';
    syncControls();

    if (lastAnnouncedSigned !== hasInk) {
      lastAnnouncedSigned = hasInk;
      notice.textContent = hasInk ? signedLabel : clearedLabel;
      notice.hidden = false;
    }

    // Announced like any other control's change so conditional logic - which
    // listens on the form - re-evaluates against the new value.
    input.dispatchEvent(new Event('change', { bubbles: true }));
  };

  /**
   * Renders a typed name to the canvas in a script face and commits it.
   *
   * The accessible route in: everything a drawn stroke produces - the ink
   * flag, the hidden input's data URL, the change event - is produced here
   * too, so the server cannot tell the two apart. The face is shrunk until the
   * name fits, so a long signature stays on the pad rather than spilling off
   * it.
   */
  const renderTyped = (text: string): void => {
    paintBackground();

    const trimmed = text.trim();
    hasInk = trimmed !== '';

    if (hasInk) {
      context.save();
      context.fillStyle = penColor;
      context.textAlign = 'center';
      context.textBaseline = 'middle';

      let fontSize = Math.round(height * 0.5);
      const applyFont = (): void => {
        context.font = `italic ${fontSize}px "Segoe Script", "Bradley Hand", "Snell Roundhand", cursive`;
      };
      applyFont();
      while (
        fontSize > 10 &&
        context.measureText(trimmed).width > width * 0.9
      ) {
        fontSize -= 2;
        applyFont();
      }

      context.fillText(trimmed, width / 2, height / 2);
      context.restore();
    }

    commit();
  };

  /**
   * (Re)sizes the backing store to whatever the CSS box currently measures,
   * scaled for the device's pixel density so a signature isn't a blurry mess
   * on a phone - read fresh every call rather than once, so a visitor who
   * changes the OS zoom level mid-session, or drags the window to another
   * display, still gets a crisp line.
   *
   * Changing a canvas's `width`/`height` clears its bitmap and resets every
   * other piece of drawing state, which is why both are reapplied here and
   * why whatever the hidden input or typed input currently hold - the source
   * of truth for what's actually signed - is redrawn afterwards rather than
   * left blank.
   */
  const resize = (): void => {
    const rect = canvas.getBoundingClientRect();
    const nextWidth = rect.width > 0 ? rect.width : configuredWidth;
    const nextHeight = rect.height > 0 ? rect.height : configuredHeight;

    if (nextWidth === width && nextHeight === height) {
      return;
    }

    width = nextWidth;
    height = nextHeight;

    const ratio = window.devicePixelRatio || 1;
    canvas.width = Math.round(width * ratio);
    canvas.height = Math.round(height * ratio);
    context.scale(ratio, ratio);
    context.lineWidth = LINE_WIDTH;
    context.lineCap = 'round';
    context.lineJoin = 'round';
    context.strokeStyle = penColor;

    if (typedInput && typedInput.value !== '') {
      renderTyped(typedInput.value);

      return;
    }

    paintBackground();

    if (input.value !== '') {
      restore(input.value, context, width, height);
    }
  };

  const positionOf = (event: PointerEvent): { x: number; y: number } => {
    const rect = canvas.getBoundingClientRect();

    // Scaled by the rendered size, so a canvas the site's CSS has resized still
    // draws under the pointer rather than offset from it.
    return {
      x:
        (event.clientX - rect.left) * (rect.width > 0 ? width / rect.width : 1),
      y:
        (event.clientY - rect.top) *
        (rect.height > 0 ? height / rect.height : 1),
    };
  };

  canvas.addEventListener('pointerdown', (event) => {
    // Switching from the typed route to drawing: wipe the rendered name and
    // clear the text input so the stroke starts on a clean pad and the two
    // representations never disagree.
    if (typedInput && typedInput.value !== '') {
      typedInput.value = '';
      paintBackground();
    }

    isDrawing = true;
    hasInk = true;

    if (typeof canvas.setPointerCapture === 'function') {
      canvas.setPointerCapture(event.pointerId);
    }

    const { x, y } = positionOf(event);
    context.beginPath();
    context.moveTo(x, y);
    // A tap that never moves should still leave a mark - a round cap on a
    // zero-length line is a dot.
    context.lineTo(x, y);
    context.stroke();

    event.preventDefault();
  });

  canvas.addEventListener('pointermove', (event) => {
    if (!isDrawing) {
      return;
    }

    const { x, y } = positionOf(event);
    context.lineTo(x, y);
    context.stroke();

    event.preventDefault();
  });

  const endStroke = (event: PointerEvent): void => {
    if (!isDrawing) {
      return;
    }

    isDrawing = false;

    if (
      typeof canvas.releasePointerCapture === 'function' &&
      canvas.hasPointerCapture?.(event.pointerId)
    ) {
      canvas.releasePointerCapture(event.pointerId);
    }

    commit();
  };

  canvas.addEventListener('pointerup', endStroke);
  canvas.addEventListener('pointercancel', endStroke);

  typedInput?.addEventListener('input', () => {
    renderTyped(typedInput.value);
  });

  clearButton?.addEventListener('click', () => {
    paintBackground();
    hasInk = false;

    if (typedInput) {
      typedInput.value = '';
    }

    commit();

    // `commit()` disables the button through `syncControls()`, which drops
    // focus to `<body>` since a disabled control can't hold it - the typed
    // input is where the visitor would sign next.
    typedInput?.focus();
  });

  // A value already in the input means the server re-rendered the step - a
  // validation failure elsewhere on the page, a step navigated back to, or a
  // resumed submission. It counts as signed straight away, before the redraw
  // `resize()` performs resolves: the value is what posts, so the clear
  // button has to be live even if the bitmap never decodes.
  if (input.value !== '') {
    hasInk = true;
  }

  resize();

  // Feature-detected rather than assumed: a browser without `ResizeObserver`
  // still gets a signature pad, just one that doesn't rescale if its
  // container's width changes after this first paint - the same
  // progressive-enhancement shape as `setPointerCapture` above.
  if (typeof ResizeObserver === 'function') {
    new ResizeObserver(resize).observe(canvas);
  }

  syncControls();
}

function restore(
  dataUrl: string,
  context: CanvasRenderingContext2D,
  width: number,
  height: number,
): void {
  const image = new Image();

  image.addEventListener('load', () => {
    context.drawImage(image, 0, 0, width, height);
  });

  // A value that isn't a decodable image is left alone rather than cleared: the
  // server is the authority on whether it's valid, and blanking it here would
  // throw away a signature over a browser quirk.
  image.addEventListener('error', () => {
    console.warn('[Formable] A stored signature could not be redrawn');
  });

  image.src = dataUrl;
}
