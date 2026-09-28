<script setup lang="ts">
import draggable from 'vuedraggable';
import { t } from '../../helpers';
import { useKeyedRows, type Keyed } from '../../useKeyedRows';

/** Mirrors models/FieldOption. */
interface Option {
  label: string;
  value: string;
  isDefault: boolean;
  isDisabled: boolean;
}

const props = defineProps<{
  modelValue: unknown;
  /** Whether more than one option may be marked default. */
  multiple: boolean;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: Option[]] }>();

function normalize(value: unknown): Option[] {
  if (!Array.isArray(value)) {
    return [];
  }

  return (value as Partial<Option>[]).map((option) => ({
    label: option.label ?? '',
    value: option.value ?? '',
    isDefault: option.isDefault ?? false,
    isDisabled: option.isDisabled ?? false,
  }));
}

const { rows, commit, add, remove, replace } = useKeyedRows<Option>(
  () => props.modelValue,
  normalize,
  (options) => emit('update:modelValue', options),
);

function setDefault(index: number, isDefault: boolean): void {
  rows.value.forEach((option, i) => {
    // A single-choice field can only have one default, so selecting one
    // clears the rest rather than letting the server reject the state.
    option.isDefault = props.multiple
      ? i === index
        ? isDefault
        : option.isDefault
      : i === index && isDefault;
  });

  commit();
}
</script>

<template>
  <div class="fb-options">
    <draggable
      :model-value="rows"
      item-key="__key"
      handle=".fb-options__grip"
      class="fb-options__list"
      @update:model-value="replace($event as (Option & Keyed)[])"
    >
      <template #item="{ element: option, index }">
        <div class="fb-options__row">
          <span
            class="fb-options__grip"
            aria-hidden="true"
            :title="t('Drag to reorder')"
            >⠿</span
          >

          <input
            v-model="option.label"
            class="fb-options__input"
            type="text"
            :placeholder="t('Label')"
            :aria-label="t('Option {number} label', { number: index + 1 })"
            @input="commit"
          />

          <input
            v-model="option.value"
            class="fb-options__input fb-options__input--code"
            type="text"
            :placeholder="option.label || t('Value')"
            :aria-label="t('Option {number} value', { number: index + 1 })"
            @input="commit"
          />

          <label class="fb-options__flag" :title="t('Selected by default')">
            <input
              :type="multiple ? 'checkbox' : 'radio'"
              :checked="option.isDefault"
              @change="
                setDefault(index, ($event.target as HTMLInputElement).checked)
              "
            />
            <span>{{ t('Default') }}</span>
          </label>

          <button
            type="button"
            class="fb-options__remove"
            :aria-label="t('Remove option {number}', { number: index + 1 })"
            @click="remove(index)"
          >
            ×
          </button>
        </div>
      </template>
    </draggable>

    <button
      type="button"
      class="fb-options__add"
      @click="
        add({ label: '', value: '', isDefault: false, isDisabled: false })
      "
    >
      + {{ t('Add option') }}
    </button>

    <p class="fb-options__hint">
      {{ t('Leave a value blank to submit the label. Values must be unique.') }}
    </p>
  </div>
</template>
