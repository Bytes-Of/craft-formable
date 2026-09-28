<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import draggable from 'vuedraggable';
import { useBuilderStore } from '../stores/builder';
import { focusAfterMove, restoreFocusAfterMove, t, uuid } from '../helpers';
import FieldCard from './FieldCard.vue';
import ConditionsEditor from './ConditionsEditor.vue';
import {
  emptyConditionSet,
  type ConditionSet,
  type FieldConfig,
  type Row,
} from '../types';

const store = useBuilderStore();

const page = computed(() => store.activePage);
const maxPerRow = computed(() => store.config?.maxFieldsPerRow ?? 4);

const showPageRules = ref(false);

/** The active page's conditions, defaulted so the editor always has a set. */
const pageConditions = computed<ConditionSet>(
  () =>
    (page.value?.settings.conditions as ConditionSet | undefined) ??
    emptyConditionSet(),
);

/** Whether the page has an active condition, for the toggle's badge. */
const pageHasConditions = computed(
  () => pageConditions.value.enabled && pageConditions.value.rules.length > 0,
);

function onPageConditionsChange(value: ConditionSet): void {
  if (page.value) {
    // Mutating the reactive page is enough - the store's deep watcher on the
    // form marks it dirty and schedules the autosave.
    page.value.settings = { ...page.value.settings, conditions: value };
  }
}

/**
 * Landing area for fields dropped below the last row.
 *
 * vuedraggable needs a real list to drop into, so the drop lands here first
 * and the watcher below promotes it to a row of its own. That keeps
 * single-column authoring - the common case - free of any row handling.
 */
const landing = ref<FieldConfig[]>([]);

watch(landing, (fields) => {
  if (fields.length === 0 || !page.value) {
    return;
  }

  page.value.rows.push({ id: uuid(), fields: [...fields] });
  store.selectedFieldId = fields[fields.length - 1].id;
  landing.value = [];
});

/**
 * A full row stops accepting fields, so the author can't build a layout the
 * server will reject.
 */
function rowGroup(row: Row) {
  return {
    name: 'fields',
    put: () => row.fields.length < maxPerRow.value,
  };
}

function onDragEnd(): void {
  store.pruneEmptyRows();
}

/** Whether merging `row` with its successor would fit under `maxFieldsPerRow`. */
function canMergeDown(row: Row, index: number): boolean {
  const next = page.value?.rows[index + 1];

  return !!next && row.fields.length + next.fields.length <= maxPerRow.value;
}

/** Finds a control on a still-present row, wherever it ends up in the DOM. */
function rowControl(
  rowId: string,
  label: string,
): HTMLButtonElement | null | undefined {
  return document
    .querySelector(`[data-row="${rowId}"]`)
    ?.querySelector<HTMLButtonElement>(`[aria-label="${label}"]`);
}

function moveRow(index: number, direction: -1 | 1): void {
  const rowId = page.value?.rows[index]?.id;
  const label = direction === -1 ? t('Move row up') : t('Move row down');

  store.moveRow(index, direction);

  if (rowId) {
    void restoreFocusAfterMove(() => rowControl(rowId, label));
  }
}

function mergeRowDown(index: number): void {
  const row = page.value?.rows[index];
  const willMerge = !!row && canMergeDown(row, index);
  const rowId = row?.id;

  store.mergeRowDown(index);

  // The merge button itself survives (the row it was clicked on is the one
  // that absorbs the fields, not the one removed), but it may now describe
  // an action that's no longer possible - the merged row's "move up" is the
  // control that still makes sense as a focus target. A no-op merge leaves
  // the clicked button's own focus alone.
  if (willMerge && rowId) {
    void focusAfterMove(() => rowControl(rowId, t('Move row up')));
  }
}
</script>

<template>
  <div v-if="page" class="fb-canvas">
    <div class="fb-canvas__page-rules">
      <button
        type="button"
        class="fb-canvas__page-rules-toggle"
        :class="{ 'fb-canvas__page-rules-toggle--on': pageHasConditions }"
        :aria-expanded="showPageRules"
        @click="showPageRules = !showPageRules"
      >
        {{ t('Page rules') }}
        <span
          v-if="pageHasConditions"
          class="fb-canvas__page-rules-badge"
          aria-hidden="true"
          >●</span
        >
      </button>

      <div v-if="showPageRules" class="fb-canvas__page-rules-body">
        <p class="fb-canvas__page-rules-help">
          {{ t('Show or hide this whole page based on earlier answers.') }}
        </p>
        <ConditionsEditor
          :model-value="pageConditions"
          @update:model-value="onPageConditionsChange"
        />
      </div>
    </div>

    <draggable
      v-model="page.rows"
      group="rows"
      item-key="id"
      handle=".fb-row__grip"
      class="fb-canvas__rows"
      @end="onDragEnd"
    >
      <template #item="{ element: row, index }">
        <div class="fb-row" :data-row="row.id">
          <div class="fb-row__handle">
            <span
              class="fb-row__grip"
              aria-hidden="true"
              :title="t('Drag to reorder row')"
              >⠿</span
            >
            <button
              type="button"
              class="fb-row__move"
              :aria-disabled="index === 0"
              :aria-label="t('Move row up')"
              @click="moveRow(index, -1)"
            >
              ↑
            </button>
            <button
              type="button"
              class="fb-row__move"
              :aria-disabled="index === page.rows.length - 1"
              :aria-label="t('Move row down')"
              @click="moveRow(index, 1)"
            >
              ↓
            </button>
            <button
              type="button"
              class="fb-row__move"
              :aria-disabled="!canMergeDown(row, index)"
              :aria-label="t('Merge with row below')"
              @click="mergeRowDown(index)"
            >
              ⇊
            </button>
          </div>

          <draggable
            :list="row.fields"
            :group="rowGroup(row)"
            item-key="id"
            class="fb-row__fields"
            @end="onDragEnd"
          >
            <template #item="{ element: field }">
              <FieldCard :field="field" />
            </template>
          </draggable>
        </div>
      </template>
    </draggable>

    <draggable
      v-model="landing"
      :group="{ name: 'fields' }"
      item-key="id"
      class="fb-canvas__dropzone"
      :class="{ 'fb-canvas__dropzone--only': page.rows.length === 0 }"
    >
      <template #header>
        <p class="fb-canvas__dropzone-label">{{ t('Drag a field here') }}</p>
      </template>
      <template #item="{ element }">
        <span class="fb-canvas__dropzone-ghost">{{ element.label }}</span>
      </template>
    </draggable>
  </div>
</template>
