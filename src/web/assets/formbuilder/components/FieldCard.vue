<script setup lang="ts">
import { computed } from 'vue';
import { useBuilderStore } from '../stores/builder';
import { focusAfterMove, restoreFocusAfterMove, t } from '../helpers';
import type { FieldConfig } from '../types';

const props = defineProps<{ field: FieldConfig }>();

const store = useBuilderStore();

const entry = computed(() => store.paletteEntry(props.field.type));
const isSelected = computed(() => store.selectedFieldId === props.field.id);

const orderIndex = computed(() =>
  store.activePageFields.findIndex((field) => field.id === props.field.id),
);
const isFirst = computed(() => orderIndex.value === 0);
const isLast = computed(
  () => orderIndex.value === store.activePageFields.length - 1,
);
const sharesRow = computed(() => {
  const row = store.activePage?.rows.find((row) =>
    row.fields.some((f) => f.id === props.field.id),
  );

  return (row?.fields.length ?? 0) > 1;
});
const errors = computed(() => store.errorsForField(props.field.id));
const errorMessages = computed(() => Object.values(errors.value).flat());

/**
 * Mirrors `FormField::$width` in the canvas so an author sizing fields can see
 * the row take shape. `auto` keeps the card on the equal-split `flex: 1 1 0`
 * the stylesheet already gives it; a sized card holds its fraction and lets
 * `auto` siblings grow into the rest, same as the rendered form.
 */
const widthBasis: Record<string, string> = {
  full: '100%',
  half: '50%',
  third: '33.333%',
  quarter: '25%',
};

const cardStyle = computed(() => {
  const basis = widthBasis[props.field.width];

  return basis ? { flex: `0 1 ${basis}` } : undefined;
});

function confirmDelete(): void {
  const label = props.field.label || entry.value?.name || t('this field');

  if (!window.confirm(t('Delete “{label}”?', { label }))) {
    return;
  }

  // The deleted card's own DOM node is gone the instant it leaves the store,
  // so where focus lands next is decided from the pre-delete field order:
  // the field that shifts into this one's place, or the one before it, or -
  // if the page is now empty - the palette's first field button.
  const fields = store.activePageFields;
  const nextId = fields[orderIndex.value + 1]?.id;
  const previousId = fields[orderIndex.value - 1]?.id;

  store.deleteField(props.field.id);

  void focusAfterMove(
    () =>
      (nextId && document.getElementById(`fb-field-${nextId}`)) ||
      (previousId && document.getElementById(`fb-field-${previousId}`)) ||
      document.querySelector<HTMLButtonElement>('.fb-palette__item'),
  );
}

/** Finds a control on this field's own card, wherever it ends up in the DOM. */
function ownControl(label: string): HTMLButtonElement | null | undefined {
  return document
    .getElementById(`fb-field-${props.field.id}`)
    ?.closest('.fb-field')
    ?.querySelector<HTMLButtonElement>(`[aria-label="${label}"]`);
}

function moveField(direction: -1 | 1): void {
  const label = direction === -1 ? t('Move field up') : t('Move field down');

  store.moveField(props.field.id, direction);
  void restoreFocusAfterMove(() => ownControl(label));
}

function split(): void {
  const willSplit = sharesRow.value;

  store.splitField(props.field.id);

  // Splitting always lands the field alone at the top of a fresh row, so
  // "move up" is the one control guaranteed to exist there. A no-op split
  // leaves the clicked button's own focus alone.
  if (willSplit) {
    void focusAfterMove(() => ownControl(t('Move field up')));
  }
}
</script>

<template>
  <div
    class="fb-field"
    :class="{
      'fb-field--selected': isSelected,
      'fb-field--error': errorMessages.length > 0,
    }"
    :style="cardStyle"
  >
    <!--
      The card is a button so it's reachable by keyboard: authors who can't
      drag still need to select a field to edit its settings.
    -->
    <button
      :id="`fb-field-${field.id}`"
      type="button"
      class="fb-field__body"
      :aria-pressed="isSelected"
      @click="store.selectedFieldId = field.id"
    >
      <span class="fb-field__type">{{ entry?.name ?? field.type }}</span>

      <span class="fb-field__label">
        {{ field.label || entry?.name || t('Untitled') }}
        <abbr
          v-if="field.required"
          class="fb-field__required"
          :title="t('Required')"
          >*</abbr
        >
      </span>

      <code v-if="entry?.hasValue" class="fb-field__handle">{{
        field.handle || '-'
      }}</code>
    </button>

    <div class="fb-field__actions">
      <button
        type="button"
        class="fb-field__action"
        :aria-disabled="isFirst"
        :aria-label="t('Move field up')"
        @click.stop="moveField(-1)"
      >
        ↑
      </button>
      <button
        type="button"
        class="fb-field__action"
        :aria-disabled="isLast"
        :aria-label="t('Move field down')"
        @click.stop="moveField(1)"
      >
        ↓
      </button>
      <button
        type="button"
        class="fb-field__action"
        :aria-disabled="!sharesRow"
        :aria-label="t('Split into own row')"
        @click.stop="split()"
      >
        ⏎
      </button>
      <button
        type="button"
        class="fb-field__action"
        :aria-label="t('Duplicate')"
        @click.stop="store.duplicateField(field.id)"
      >
        ⧉
      </button>
      <button
        type="button"
        class="fb-field__action fb-field__action--danger"
        :aria-label="t('Delete')"
        @click.stop="confirmDelete()"
      >
        ×
      </button>
    </div>

    <ul v-if="errorMessages.length" class="fb-field__errors">
      <li v-for="message in errorMessages" :key="message">{{ message }}</li>
    </ul>
  </div>
</template>
