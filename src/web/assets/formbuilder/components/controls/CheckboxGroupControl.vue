<script setup lang="ts">
import { t } from '../../helpers';
import type { SelectOption } from '../../types';

const props = defineProps<{
  id: string;
  options: SelectOption[];
  modelValue: string[];
}>();

const emit = defineEmits<{ 'update:modelValue': [value: string[]] }>();

function toggle(value: string, checked: boolean): void {
  const next = new Set(props.modelValue);

  if (checked) {
    next.add(value);
  } else {
    next.delete(value);
  }

  // Emit in the options' order rather than click order, so the stored value
  // doesn't churn just because the author toggled things in a different order.
  emit(
    'update:modelValue',
    props.options
      .map((option) => String(option.value))
      .filter((option) => next.has(option)),
  );
}
</script>

<template>
  <fieldset :id="id" class="fb-checkboxes">
    <p v-if="options.length === 0" class="fb-checkboxes__empty">
      {{ t('Nothing to choose from.') }}
    </p>

    <label
      v-for="option in options"
      :key="String(option.value)"
      class="fb-checkboxes__item"
    >
      <input
        type="checkbox"
        :value="String(option.value)"
        :checked="modelValue.includes(String(option.value))"
        @change="
          toggle(
            String(option.value),
            ($event.target as HTMLInputElement).checked,
          )
        "
      />
      <span>{{ option.label }}</span>
    </label>
  </fieldset>
</template>
