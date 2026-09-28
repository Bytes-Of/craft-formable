/**
 * File-upload enhancer.
 *
 * The field renders a plain `<input type="file">` and, without this module,
 * that is the whole control: no real drop target (dragging onto the bare
 * input does nothing), no thumbnail or upload progress, no list of what has
 * been chosen, no way to drop one file from a multi-file selection without
 * starting the pick over, and no size check until the bytes have already been
 * uploaded and the server turns them away. This adds all of that.
 *
 * The dropzone is the invisible-input-over-a-decorative-box pattern: the
 * input moves inside `.formable-file__dropzone`, stretched to cover it and
 * painted transparent, and stays the last child so it's what actually
 * receives every click and keystroke - the icon and prompt behind it
 * (`aria-hidden`) are for sighted mouse users only, and a screen reader or a
 * keyboard both still see and operate a plain, focusable file input. Compare
 * `multi-select.ts`, which hides its native control outright because it
 * builds a full replacement widget; this field has no replacement for
 * "click here" beyond the input itself, so the input stays interactive
 * rather than being hidden.
 *
 * Nothing here is load-bearing. The input still posts its own files, and the
 * submission pipeline re-checks the size, the count and the allowed kinds
 * regardless of what happened in the browser - the client-side size check
 * (`FileUpload::getMaxFileSizeInBytes()`, rendered into `data-max-bytes`) just
 * means the visitor hears about an oversize file before waiting for it to
 * upload rather than after.
 *
 * Rebuilding the input's `FileList` after a removal needs `DataTransfer`.
 * Every current browser has it and no older one polyfills it, so where it is
 * missing the field is left exactly as the server rendered it - a working
 * plain file input, dropzone included: without JS none of this module runs,
 * so the wrapper never gains `--enhanced` and the input renders where the
 * server put it.
 *
 * Contract (see `enhancers.ts`): idempotent (claims the wrapper with
 * `data-formable-file-upload-ready`), progressive (the input works without
 * it), contained (a throw is caught by `applyEnhancers`).
 */

const SELECTOR =
  '[data-formable-file-upload]:not([data-formable-file-upload-ready])';

/** Placeholder the Twig side leaves in translated strings for a filename. */
const NAME_TOKEN = '__NAME__';

/** Placeholder the Twig side leaves in translated strings for a file count. */
const COUNT_TOKEN = '__COUNT__';

export function enhanceFileUploads(root: ParentNode): void {
  root
    .querySelectorAll<HTMLElement>(SELECTOR)
    .forEach((wrapper) => setUp(wrapper));
}

/**
 * Zeroes every file's progress bar. `main.ts` calls this at the start of each
 * submit attempt, so a retry after a validation error doesn't briefly show
 * the previous attempt's fill before the first `progress` event arrives.
 */
export function resetUploadProgress(form: HTMLFormElement): void {
  form
    .querySelectorAll<HTMLElement>('[data-formable-file-progress]')
    .forEach((bar) => {
      bar.setAttribute('aria-valuenow', '0');
      const fill = bar.querySelector<HTMLElement>(
        '.formable-field__file-progress-bar',
      );

      if (fill) {
        fill.style.width = '0%';
      }
    });
}

/**
 * Splits one request's aggregate upload progress across the files actually
 * selected, in the order `FormData` will serialize them - the order the
 * enhanced fields appear in the form, and within a field, the order in its
 * `FileList`.
 *
 * This is an approximation, not a byte-accurate readout: `total`/`loaded`
 * count the whole multipart body (other fields, part headers, the
 * boundary), while the per-file windows below are cut from file sizes alone.
 * The error this introduces is small next to typical file sizes and it's
 * self-correcting - a file's window always closes at exactly 100% once
 * `loaded` reaches its end, regardless of how the overhead was distributed.
 * Good enough for a progress bar; not a guarantee any one file finished
 * uploading at the instant its bar reads 100%.
 */
export function reportUploadProgress(
  form: HTMLFormElement,
  loaded: number,
  total: number,
): void {
  if (total <= 0) {
    return;
  }

  let offset = 0;

  form
    .querySelectorAll<HTMLElement>('[data-formable-file-upload-ready]')
    .forEach((wrapper) => {
      const input =
        wrapper.querySelector<HTMLInputElement>('input[type="file"]');

      Array.from(input?.files ?? []).forEach((file, index) => {
        const start = offset;
        const end = start + file.size;
        offset = end;

        const fraction =
          end === start ? 1 : clamp((loaded - start) / (end - start), 0, 1);
        const percent = Math.round(fraction * 100);

        const bar = wrapper.querySelector<HTMLElement>(
          `[data-formable-file-progress="${index}"]`,
        );

        if (!bar) {
          return;
        }

        bar.setAttribute('aria-valuenow', String(percent));

        const fill = bar.querySelector<HTMLElement>(
          '.formable-field__file-progress-bar',
        );

        if (fill) {
          fill.style.width = `${percent}%`;
        }
      });
    });
}

function clamp(value: number, min: number, max: number): number {
  return Math.min(max, Math.max(min, value));
}

function setUp(wrapper: HTMLElement): void {
  const input = wrapper.querySelector<HTMLInputElement>('input[type="file"]');

  // No input (a template override that renders its own), or a browser without
  // `DataTransfer` (so a removal can't rewrite the `FileList`). Left unclaimed
  // and untouched: the native input still posts normally.
  if (!input || typeof DataTransfer !== 'function') {
    return;
  }

  wrapper.dataset.formableFileUploadReady = 'true';
  wrapper.classList.add('formable-file--enhanced');

  const maxBytes = Number(wrapper.dataset.maxBytes) || null;
  const limit = Math.max(1, Number(wrapper.dataset.limit) || 1);
  const sizeError = wrapper.dataset.sizeError ?? '';
  const countError = wrapper.dataset.countError ?? '';
  const removeLabel = wrapper.dataset.removeLabel ?? `Remove ${NAME_TOKEN}`;
  const uploadingLabel =
    wrapper.dataset.uploadingLabel ?? `Uploading ${NAME_TOKEN}`;
  const selectedLabel =
    wrapper.dataset.selectedLabel ?? `${COUNT_TOKEN} file(s) selected`;
  const addedLabel =
    wrapper.dataset.addedLabel ?? `${COUNT_TOKEN} file(s) added`;
  const removedLabel = wrapper.dataset.removedLabel ?? `Removed ${NAME_TOKEN}`;

  // The icon and prompt are `aria-hidden` and purely decorative - the input
  // beneath them (moved in below, so it stays the last child and paints on
  // top) is the real, focusable, keyboard-operable control.
  const dropzone = document.createElement('div');
  dropzone.className = 'formable-file__dropzone';
  dropzone.innerHTML =
    '<svg class="formable-file__icon" viewBox="0 0 20 20" fill="none" focusable="false" aria-hidden="true">' +
    '<path d="M10 3v9m0-9 3.25 3.25M10 3 6.75 6.25" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>' +
    '<path d="M4.5 13.5V15A1.5 1.5 0 0 0 6 16.5h8a1.5 1.5 0 0 0 1.5-1.5v-1.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/>' +
    '</svg>';

  // "Drag files here" and "click to browse" are both meaningless on a touch
  // device - there's no cursor to click with and no drag gesture a visitor
  // would expect to work here. `data-prompt-touch` (`fileUpload.twig`) is the
  // same string in different words for a coarse primary pointer; a form
  // rendered before load or moved between contexts still gets the right copy
  // for the device reading it, since this only runs once, at enhancement
  // time. See [[frontend-embeddability-review]] chunk 10 (L2).
  const isTouch = window.matchMedia('(pointer: coarse)').matches;
  const promptText =
    (isTouch ? wrapper.dataset.promptTouch : undefined) ??
    wrapper.dataset.prompt ??
    '';
  if (promptText !== '') {
    const prompt = document.createElement('p');
    prompt.className = 'formable-file__prompt';
    prompt.setAttribute('aria-hidden', 'true');
    prompt.textContent = promptText;
    dropzone.appendChild(prompt);
  }

  wrapper.insertBefore(dropzone, input);
  dropzone.appendChild(input);

  const list = document.createElement('ul');
  list.className = 'formable-field__files';
  list.dataset.formableFileList = '';
  list.hidden = true;
  wrapper.appendChild(list);

  // Informational, not an error: an oversize file the visitor can still swap
  // out is not the same as a submitted field that failed validation, so this
  // is a polite live region rather than an assertive one.
  const notice = document.createElement('p');
  notice.className = 'formable-file__notice';
  notice.setAttribute('role', 'status');
  notice.setAttribute('aria-live', 'polite');
  notice.hidden = true;
  wrapper.appendChild(notice);

  // A second, neutral region: `notice` above is specifically the rejected-file
  // warning and stays styled as an error, so a successful add or a removal -
  // neither one a problem - gets its own announcement instead of borrowing that
  // one's error colour.
  const announce = document.createElement('p');
  announce.className = 'formable-field__notice';
  announce.setAttribute('role', 'status');
  announce.setAttribute('aria-live', 'polite');
  announce.hidden = true;
  wrapper.appendChild(announce);

  // `setFiles()` doesn't fire `change` on its own, so `commit()` dispatches
  // one by hand - which would re-enter the input's own `change` handler. The
  // flag lets that handler skip a change it caused itself.
  let syncing = false;
  let dragDepth = 0;

  // Object URLs handed to `<img>` thumbnails - revoked and rebuilt on every
  // `renderList()` rather than left for the browser to reclaim on unload,
  // since a long session can pick, remove and re-pick files many times over.
  let thumbUrls: string[] = [];

  const revokeThumbs = (): void => {
    thumbUrls.forEach((url) => URL.revokeObjectURL(url));
    thumbUrls = [];
  };

  /** An image preview where the file type allows it; a generic glyph otherwise. */
  const buildThumb = (file: File): HTMLElement => {
    const canPreview =
      file.type.startsWith('image/') &&
      typeof URL.createObjectURL === 'function';

    if (canPreview) {
      const url = URL.createObjectURL(file);
      thumbUrls.push(url);

      const img = document.createElement('img');
      img.className = 'formable-field__file-thumb';
      img.src = url;
      img.alt = '';

      return img;
    }

    const glyph = document.createElement('span');
    glyph.className =
      'formable-field__file-thumb formable-field__file-thumb--icon';
    glyph.setAttribute('aria-hidden', 'true');
    glyph.innerHTML =
      '<svg viewBox="0 0 20 20" fill="none" focusable="false">' +
      '<path d="M5.5 2.5h6l4 4V17a.5.5 0 0 1-.5.5h-9A.5.5 0 0 1 5 17V3a.5.5 0 0 1 .5-.5Z" stroke="currentColor" stroke-width="1.25" stroke-linejoin="round"/>' +
      '<path d="M11.5 2.5V6a.5.5 0 0 0 .5.5h3.5" stroke="currentColor" stroke-width="1.25" stroke-linejoin="round"/>' +
      '</svg>';

    return glyph;
  };

  const currentFiles = (): File[] => Array.from(input.files ?? []);

  const setFiles = (files: File[]): void => {
    const data = new DataTransfer();
    files.forEach((file) => data.items.add(file));
    input.files = data.files;
  };

  const setNotice = (messages: string[]): void => {
    notice.textContent = messages.join(' ');
    notice.hidden = messages.length === 0;
  };

  const setAnnouncement = (message: string): void => {
    announce.textContent = message;
    announce.hidden = message === '';
  };

  /**
   * Trims a candidate list to what the field will accept, collecting a
   * message for anything dropped. Mirrors the server's own checks - an
   * oversize file, then more files than the limit allows.
   */
  const vet = (files: File[]): { accepted: File[]; messages: string[] } => {
    const messages: string[] = [];
    let accepted = files;

    if (maxBytes !== null) {
      accepted = accepted.filter((file) => {
        if (file.size <= maxBytes) {
          return true;
        }

        if (sizeError !== '') {
          messages.push(sizeError.replace(NAME_TOKEN, file.name));
        }

        return false;
      });
    }

    if (accepted.length > limit) {
      accepted = accepted.slice(0, limit);

      if (countError !== '') {
        messages.push(countError);
      }
    }

    return { accepted, messages };
  };

  const renderList = (): void => {
    const files = currentFiles();

    revokeThumbs();
    list.textContent = '';

    files.forEach((file, index) => {
      const item = document.createElement('li');
      item.className = 'formable-field__file';
      item.appendChild(buildThumb(file));

      const info = document.createElement('div');
      info.className = 'formable-field__file-info';

      const label = document.createElement('span');
      label.className = 'formable-field__file-name';
      label.textContent = `${file.name} (${formatBytes(file.size)})`;
      info.appendChild(label);

      const progress = document.createElement('div');
      progress.className = 'formable-field__file-progress';
      progress.dataset.formableFileProgress = String(index);
      progress.setAttribute('role', 'progressbar');
      progress.setAttribute('aria-valuemin', '0');
      progress.setAttribute('aria-valuemax', '100');
      progress.setAttribute('aria-valuenow', '0');
      progress.setAttribute(
        'aria-label',
        uploadingLabel.replace(NAME_TOKEN, file.name),
      );

      const bar = document.createElement('div');
      bar.className = 'formable-field__file-progress-bar';
      progress.appendChild(bar);
      info.appendChild(progress);

      item.appendChild(info);

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.className =
        'formable-button formable-button--ghost formable-button--sm formable-file__remove';
      remove.dataset.formableFileRemove = String(index);
      remove.setAttribute(
        'aria-label',
        removeLabel.replace(NAME_TOKEN, file.name),
      );

      const glyph = document.createElement('span');
      glyph.setAttribute('aria-hidden', 'true');
      glyph.textContent = '×';
      remove.appendChild(glyph);

      item.appendChild(remove);
      list.appendChild(item);
    });

    list.hidden = files.length === 0;
  };

  const commit = (files: File[]): void => {
    syncing = true;
    setFiles(files);
    renderList();
    // Files are values: conditional logic listening on the form has to see the
    // field's contents change.
    input.dispatchEvent(new Event('change', { bubbles: true }));
    syncing = false;
  };

  input.addEventListener('change', () => {
    if (syncing) {
      return;
    }

    const before = currentFiles();
    const { accepted, messages } = vet(before);

    setNotice(messages);
    setAnnouncement(
      accepted.length > 0
        ? selectedLabel.replace(COUNT_TOKEN, String(accepted.length))
        : '',
    );

    if (accepted.length !== before.length) {
      commit(accepted);
    } else {
      renderList();
    }
  });

  wrapper.addEventListener('click', (event) => {
    const target = event.target as HTMLElement | null;
    const button = target?.closest<HTMLElement>('[data-formable-file-remove]');

    if (!button) {
      return;
    }

    const index = Number(button.dataset.formableFileRemove);
    const removedFile = currentFiles()[index];

    setNotice([]);
    setAnnouncement(
      removedFile ? removedLabel.replace(NAME_TOKEN, removedFile.name) : '',
    );
    commit(currentFiles().filter((_, position) => position !== index));

    // `commit()` rebuilds the whole list, so the button that had focus is
    // gone. The file that shifted into this slot gets the same index the
    // removed one had; with nothing left to shift up, the input is the next
    // reachable control.
    const next = list.querySelector<HTMLElement>(
      `[data-formable-file-remove="${index}"]`,
    );
    (next ?? input).focus();
  });

  const setDragover = (on: boolean): void => {
    wrapper.classList.toggle('formable-file--dragover', on);
  };

  // `dragenter`/`dragleave` fire for every descendant the pointer crosses, so
  // the highlight is keyed off a depth count rather than the last event.
  wrapper.addEventListener('dragenter', (event) => {
    event.preventDefault();
    dragDepth += 1;
    setDragover(true);
  });

  wrapper.addEventListener('dragover', (event) => {
    event.preventDefault();

    if (event.dataTransfer) {
      event.dataTransfer.dropEffect = 'copy';
    }
  });

  wrapper.addEventListener('dragleave', () => {
    dragDepth = Math.max(0, dragDepth - 1);

    if (dragDepth === 0) {
      setDragover(false);
    }
  });

  wrapper.addEventListener('drop', (event) => {
    event.preventDefault();
    dragDepth = 0;
    setDragover(false);

    const dropped = Array.from(event.dataTransfer?.files ?? []);

    if (dropped.length === 0) {
      return;
    }

    // A `multiple` field adds to what's there; a single-file one replaces.
    const before = currentFiles();
    const merged = input.multiple ? [...before, ...dropped] : [dropped[0]];
    const { accepted, messages } = vet(merged);
    const addedCount = Math.max(0, accepted.length - before.length);

    setNotice(messages);
    setAnnouncement(
      addedCount > 0 ? addedLabel.replace(COUNT_TOKEN, String(addedCount)) : '',
    );
    commit(accepted);
  });

  // A file input can't be pre-populated by the server, so there is normally
  // nothing to show on load - but a browser restoring the page from its
  // back/forward cache can carry a selection over, and this picks that up.
  renderList();
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) {
    return `${bytes} B`;
  }

  const units = ['KB', 'MB', 'GB'];
  let value = bytes / 1024;
  let unitIndex = 0;

  while (value >= 1024 && unitIndex < units.length - 1) {
    value /= 1024;
    unitIndex += 1;
  }

  return `${value.toFixed(1)} ${units[unitIndex]}`;
}
