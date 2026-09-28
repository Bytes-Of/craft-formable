<script setup lang="ts">
import { nextTick, ref } from 'vue';
import { useBuilderStore } from '../stores/builder';
import { focusAfterMove, restoreFocusAfterMove, t } from '../helpers';

const store = useBuilderStore();
const editingIndex = ref<number | null>(null);
const labelInput = ref<HTMLInputElement | null>(null);
let labelBeforeRename = '';

async function startRename(index: number): Promise<void> {
  labelBeforeRename = store.form.pages[index]?.label ?? '';
  editingIndex.value = index;
  await nextTick();
  labelInput.value?.focus();
  labelInput.value?.select();
}

function focusRenameButton(pageId: string | undefined): void {
  if (pageId) {
    void focusAfterMove(() => pageControl(pageId, t('Rename')));
  }
}

/** Enter commits the typed label (already written by v-model) and unmounts
 * the input, dropping focus to `<body>` unless it's restored here. */
function commitRename(index: number): void {
  const pageId = store.form.pages[index]?.id;

  editingIndex.value = null;
  focusRenameButton(pageId);
}

/** Esc discards what was typed, restoring the label captured in startRename. */
function cancelRename(index: number): void {
  const page = store.form.pages[index];

  if (page) {
    page.label = labelBeforeRename;
  }

  editingIndex.value = null;
  focusRenameButton(page?.id);
}

/** Blur commits like Enter, but focus is already going wherever the author
 * clicked or tabbed to, so it isn't forced back onto the Rename button. */
function endRename(): void {
  editingIndex.value = null;
}

function pageError(pageId: string): string | null {
  const errors = store.layoutErrors.pages[pageId];

  return errors ? (Object.values(errors)[0]?.[0] ?? null) : null;
}

function confirmDelete(index: number): void {
  const page = store.form.pages[index];
  const fieldCount = page.rows.reduce(
    (total, row) => total + row.fields.length,
    0,
  );

  // Only interrupt when there's work to lose; deleting an empty page is
  // trivially undoable by adding another.
  if (
    fieldCount > 0 &&
    !window.confirm(
      t('Delete “{label}” and its {count} field(s)?', {
        label: page.label,
        count: fieldCount,
      }),
    )
  ) {
    return;
  }

  store.deletePage(index);

  // deletePage() already clamps activePageIndex to the page that takes the
  // deleted tab's slot (or the previous one, if it was the last tab), so the
  // tab to focus is just whichever page that leaves active.
  void focusAfterMove(() => {
    const nextActive = store.form.pages[store.activePageIndex];

    return nextActive && pageTab(nextActive.id);
  });
}

/** The clickable tab button itself, as opposed to one of its aria-labelled controls. */
function pageTab(pageId: string): HTMLButtonElement | null | undefined {
  return document.querySelector<HTMLButtonElement>(
    `[data-page="${pageId}"] .fb-pages__tab`,
  );
}

/** Finds a control on a still-present page's tab, wherever it lands in the DOM. */
function pageControl(
  pageId: string,
  label: string,
): HTMLButtonElement | null | undefined {
  return document
    .querySelector(`[data-page="${pageId}"]`)
    ?.querySelector<HTMLButtonElement>(`[aria-label="${label}"]`);
}

function move(index: number, direction: -1 | 1): void {
  const pageId = store.form.pages[index]?.id;
  const label = direction === -1 ? t('Move left') : t('Move right');

  store.movePage(index, index + direction);

  if (pageId) {
    void restoreFocusAfterMove(() => pageControl(pageId, label));
  }
}
</script>

<template>
  <div class="fb-pages">
    <nav class="fb-pages__list" :aria-label="t('Form pages')">
      <div
        v-for="(page, index) in store.form.pages"
        :key="page.id"
        class="fb-pages__item"
        :data-page="page.id"
      >
        <input
          v-if="editingIndex === index"
          :ref="(el) => (labelInput = el as HTMLInputElement | null)"
          v-model="page.label"
          class="fb-pages__rename"
          type="text"
          :aria-label="t('Page {number} name', { number: index + 1 })"
          @blur="endRename()"
          @keydown.enter.prevent="commitRename(index)"
          @keydown.esc.prevent="cancelRename(index)"
        />

        <button
          v-else
          type="button"
          class="fb-pages__tab"
          :class="{
            'fb-pages__tab--active': store.activePageIndex === index,
            'fb-pages__tab--error': store.pagesWithErrors.has(index),
          }"
          :aria-current="store.activePageIndex === index ? 'page' : undefined"
          :title="pageError(page.id) ?? undefined"
          @click="store.activePageIndex = index"
          @dblclick="startRename(index)"
        >
          {{ page.label || t('Page {number}', { number: index + 1 }) }}
          <template v-if="store.pagesWithErrors.has(index)">
            <span class="fb-pages__error-dot" aria-hidden="true">!</span>
            <span class="fb__sr-only">{{ t('has errors') }}</span>
          </template>
        </button>

        <span v-if="store.activePageIndex === index" class="fb-pages__controls">
          <button
            type="button"
            class="fb-pages__control"
            :aria-label="t('Rename')"
            @click="startRename(index)"
          >
            ✎
          </button>
          <button
            type="button"
            class="fb-pages__control"
            :aria-disabled="index === 0"
            :aria-label="t('Move left')"
            @click="move(index, -1)"
          >
            ←
          </button>
          <button
            type="button"
            class="fb-pages__control"
            :aria-disabled="index === store.form.pages.length - 1"
            :aria-label="t('Move right')"
            @click="move(index, 1)"
          >
            →
          </button>
          <button
            type="button"
            class="fb-pages__control fb-pages__control--danger"
            :disabled="store.form.pages.length <= 1"
            :aria-label="t('Delete page')"
            @click="confirmDelete(index)"
          >
            ×
          </button>
        </span>
      </div>
    </nav>

    <button type="button" class="fb-pages__add" @click="store.addPage()">
      + {{ t('Add page') }}
    </button>
  </div>
</template>
