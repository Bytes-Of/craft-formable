<script setup lang="ts">
import { computed } from 'vue';
import { t } from '../../helpers';
import type { SubFieldDefinition } from '../../types';

/**
 * Editor for a composite field's sub-inputs (name, address).
 *
 * The stored value is sparse: it holds only the sub-fields the author has
 * actually changed, so the PHP defaults stay authoritative and a later change
 * to them reaches existing forms.
 */
type Overrides = Record<
  string,
  { enabled?: boolean; required?: boolean; label?: string }
>;

const props = defineProps<{
  modelValue: unknown;
  definitions: Record<string, SubFieldDefinition>;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: Overrides] }>();

const overrides = computed<Overrides>(() =>
  props.modelValue && typeof props.modelValue === 'object'
    ? (props.modelValue as Overrides)
    : {},
);

const subFields = computed(() =>
  Object.entries(props.definitions).map(([handle, definition]) => {
    const override = overrides.value[handle] ?? {};

    return {
      handle,
      label: override.label ?? definition.label ?? handle,
      enabled: override.enabled ?? definition.enabled ?? true,
      required: override.required ?? definition.required ?? false,
    };
  }),
);

const enabledCount = computed(
  () => subFields.value.filter((sub) => sub.enabled).length,
);

function update(handle: string, changes: Partial<Overrides[string]>): void {
  emit('update:modelValue', {
    ...overrides.value,
    [handle]: { ...(overrides.value[handle] ?? {}), ...changes },
  });
}
</script>

<template>
  <div class="fb-subfields">
    <div v-for="sub in subFields" :key="sub.handle" class="fb-subfields__row">
      <label class="fb-subfields__toggle">
        <input
          type="checkbox"
          :checked="sub.enabled"
          :disabled="sub.enabled && enabledCount === 1"
          :title="
            sub.enabled && enabledCount === 1
              ? t('At least one sub-field must stay enabled.')
              : undefined
          "
          @change="
            update(sub.handle, {
              enabled: ($event.target as HTMLInputElement).checked,
            })
          "
        />
        <code class="fb-subfields__handle">{{ sub.handle }}</code>
      </label>

      <input
        class="fb-subfields__label"
        type="text"
        :value="sub.label"
        :disabled="!sub.enabled"
        :aria-label="t('{handle} label', { handle: sub.handle })"
        @input="
          update(sub.handle, {
            label: ($event.target as HTMLInputElement).value,
          })
        "
      />

      <label class="fb-subfields__required">
        <input
          type="checkbox"
          :checked="sub.required"
          :disabled="!sub.enabled"
          @change="
            update(sub.handle, {
              required: ($event.target as HTMLInputElement).checked,
            })
          "
        />
        <span>{{ t('Required') }}</span>
      </label>
    </div>
  </div>
</template>
