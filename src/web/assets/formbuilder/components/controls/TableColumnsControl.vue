<script setup lang="ts">
import draggable from 'vuedraggable';
import { camelize, t } from '../../helpers';
import { useKeyedRows, type Keyed } from '../../useKeyedRows';

/** Mirrors models/TableColumn. */
interface Column {
  handle: string;
  label: string;
  type: string;
  width: string;
  options: string[];
}

/** The column types an author can pick, with the label each renders as. */
const columnTypes = (): { value: string; label: string }[] => [
  { value: 'text', label: t('Text') },
  { value: 'number', label: t('Number') },
  { value: 'email', label: t('Email') },
  { value: 'url', label: t('URL') },
  { value: 'select', label: t('Dropdown') },
  { value: 'checkbox', label: t('Checkbox') },
];

const props = defineProps<{ modelValue: unknown }>();
const emit = defineEmits<{ 'update:modelValue': [value: Column[]] }>();

function normalize(value: unknown): Column[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return (value as Partial<Column>[]).map((column) => ({
    handle: column.handle ?? '',
    label: column.label ?? '',
    type: column.type ?? 'text',
    width: column.width ?? '',
    options: Array.isArray(column.options) ? column.options : [],
  }));
}

const { rows, commit, add, remove, replace } = useKeyedRows<Column>(
  () => props.modelValue,
  normalize,
  (columns) => emit('update:modelValue', columns),
);

/** Fills an untouched handle from the label, as the field drawer does. */
function onLabelInput(index: number): void {
  const column = rows.value[index];

  if (
    column.handle === '' ||
    column.handle === camelize(column.label.slice(0, -1))
  ) {
    column.handle = camelize(column.label);
  }

  commit();
}

/** `select` columns carry their own choices, edited as one per line. */
function setOptions(index: number, raw: string): void {
  rows.value[index].options = raw
    .split('\n')
    .map((option) => option.trim())
    .filter(Boolean);

  commit();
}
</script>

<template>
  <div class="fb-columns">
    <draggable
      :model-value="rows"
      item-key="__key"
      handle=".fb-columns__grip"
      class="fb-columns__list"
      @update:model-value="replace($event as (Column & Keyed)[])"
    >
      <template #item="{ element: column, index }">
        <div class="fb-columns__row">
          <span
            class="fb-columns__grip"
            aria-hidden="true"
            :title="t('Drag to reorder')"
            >⠿</span
          >

          <input
            v-model="column.label"
            class="fb-columns__input"
            type="text"
            :placeholder="t('Heading')"
            :aria-label="t('Column {number} heading', { number: index + 1 })"
            @input="onLabelInput(index)"
          />

          <input
            v-model="column.handle"
            class="fb-columns__input fb-columns__input--code"
            type="text"
            :placeholder="t('handle')"
            spellcheck="false"
            :aria-label="t('Column {number} handle', { number: index + 1 })"
            @input="commit"
          />

          <select
            v-model="column.type"
            class="fb-columns__input fb-columns__input--type"
            :aria-label="t('Column {number} type', { number: index + 1 })"
            @change="commit"
          >
            <option
              v-for="type in columnTypes()"
              :key="type.value"
              :value="type.value"
            >
              {{ type.label }}
            </option>
          </select>

          <input
            v-model="column.width"
            class="fb-columns__input fb-columns__input--width"
            type="text"
            :placeholder="t('Width')"
            :aria-label="t('Column {number} width', { number: index + 1 })"
            @input="commit"
          />

          <button
            type="button"
            class="fb-columns__remove"
            :aria-label="t('Remove column {number}', { number: index + 1 })"
            @click="remove(index)"
          >
            ×
          </button>

          <textarea
            v-if="column.type === 'select'"
            class="fb-columns__options"
            rows="3"
            :placeholder="t('One choice per line')"
            :value="column.options.join('\n')"
            :aria-label="t('Column {number} choices', { number: index + 1 })"
            @input="
              setOptions(index, ($event.target as HTMLTextAreaElement).value)
            "
          ></textarea>
        </div>
      </template>
    </draggable>

    <button
      type="button"
      class="fb-columns__add"
      @click="
        add({ handle: '', label: '', type: 'text', width: '', options: [] })
      "
    >
      + {{ t('Add column') }}
    </button>
  </div>
</template>
