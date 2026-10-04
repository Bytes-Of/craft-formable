<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useBuilderStore } from '../stores/builder';
import { camelize, t } from '../helpers';
import CheckboxGroupControl from './controls/CheckboxGroupControl.vue';
import OptionsControl from './controls/OptionsControl.vue';
import SubFieldsControl from './controls/SubFieldsControl.vue';
import TableColumnsControl from './controls/TableColumnsControl.vue';
import type { SelectOption, SettingSchema } from '../types';

const props = defineProps<{
  setting: SettingSchema;
  modelValue: unknown;
  errors?: string[];
  warning?: string | null;
  /** For `handle` controls: the current value of the setting it derives from. */
  sourceValue?: unknown;
  /** For `handle` controls: the ID of the field this setting belongs to. */
  fieldId?: string;
}>();

const emit = defineEmits<{ 'update:modelValue': [value: unknown] }>();

const store = useBuilderStore();
const inputId = computed(() => `fb-setting-${props.setting.name}`);

/**
 * The warning and errors join the instructions so a screen reader on the
 * input hears why it's marked invalid, not just that it is.
 */
const describedBy = computed(() => {
  const ids = [
    props.setting.instructions ? `${inputId.value}-instructions` : null,
    props.warning ? `${inputId.value}-warning` : null,
    props.errors?.length ? `${inputId.value}-errors` : null,
  ].filter((id) => id !== null);

  return ids.length ? ids.join(' ') : undefined;
});

function update(value: unknown): void {
  emit('update:modelValue', value);
}

/**
 * Handle auto-generation stops as soon as the author edits the handle
 * directly - the same rule Craft's HandleGenerator uses, so a deliberate
 * handle is never silently overwritten by a later label edit. It also starts
 * stopped for a field that's already in the saved layout, so fixing a typo
 * in its label can't silently rename the handle out from under stored
 * answers and conditions that point at it.
 */
function isSavedField(): boolean {
  return props.fieldId !== undefined && store.savedFieldIds.has(props.fieldId);
}

const handleTouched = ref(isSavedField());

const stringValue = computed(() =>
  props.modelValue == null ? '' : String(props.modelValue),
);

/** The handle as it stood at the start of the current edit - see `onHandleBlur()`. */
const originalHandle = ref(stringValue.value);

watch(
  () => props.fieldId,
  () => {
    handleTouched.value = isSavedField();
    originalHandle.value = stringValue.value;
  },
);

/**
 * Confirms a rename of an already-saved field's handle once the author
 * leaves the input, not on every keystroke - a mid-edit value isn't the one
 * that's about to stick, and confirming per character would turn fixing a
 * typo into a wall of dialogs. `renameHandle()` already rewrote the
 * conditions and mappings that pointed at the old handle, and the server moves
 * stored answers to the new one on save - but in the background, so the author
 * is told that, and that a token in a notification's text is theirs to update.
 */
async function onHandleBlur(): Promise<void> {
  if (!isSavedField() || stringValue.value === originalHandle.value) {
    return;
  }

  const renamedFrom = originalHandle.value;
  const renamedTo = stringValue.value;

  if (
    (await store.getSubmissionCount()) > 0 &&
    !window.confirm(
      t(
        'This form has stored submissions. Saving will move their answers from “{old}” to “{new}” in the background, and any notification text that still uses the old handle needs updating by hand. Continue?',
        { old: renamedFrom, new: renamedTo },
      ),
    )
  ) {
    update(renamedFrom);

    return;
  }

  originalHandle.value = renamedTo;
}

watch(
  () => props.sourceValue,
  (source) => {
    if (
      props.setting.type !== 'handle' ||
      handleTouched.value ||
      typeof source !== 'string'
    ) {
      return;
    }

    update(store.uniqueHandle(camelize(source)));
  },
);

/** Options for select-ish controls, resolved from the schema or the CP config. */
const options = computed<SelectOption[]>(() => {
  if (props.setting.type === 'volumeSelect') {
    return store.config?.volumes ?? [];
  }

  if (props.setting.type === 'elementSources') {
    return store.config?.elementSources[props.setting.elementType ?? ''] ?? [];
  }

  return props.setting.options ?? [];
});

/** The native `<input type>` a text-ish control renders as. */
const inputType = computed(() => {
  if (props.setting.type === 'text') {
    return 'text';
  }

  return props.setting.type === 'datetime'
    ? 'datetime-local'
    : props.setting.type;
});

/**
 * A date/datetime value shaped for the native control. The setting is stored as
 * ISO-8601 (with an offset); the picker wants a bare local wall-clock string -
 * `YYYY-MM-DD` for a date, `YYYY-MM-DDTHH:MM` for a datetime.
 */
const dateInputValue = computed(() => {
  if (typeof props.modelValue !== 'string' || props.modelValue === '') {
    return '';
  }

  return props.setting.type === 'datetime'
    ? props.modelValue.slice(0, 16)
    : props.modelValue.slice(0, 10);
});

const numberValue = computed(() =>
  props.modelValue === null || props.modelValue === ''
    ? ''
    : Number(props.modelValue),
);

const arrayValue = computed<string[]>(() =>
  Array.isArray(props.modelValue) ? (props.modelValue as string[]) : [],
);

function onNumberInput(event: Event): void {
  const raw = (event.target as HTMLInputElement).value;

  // Empty stays null rather than 0 - several settings treat null as "no limit".
  update(raw === '' ? null : Number(raw));
}
</script>

<template>
  <div class="fb-setting" :class="{ 'fb-setting--error': errors?.length }">
    <label
      v-if="setting.type !== 'lightswitch'"
      class="fb-setting__label"
      :for="inputId"
    >
      {{ setting.label }}
      <span
        v-if="setting.required"
        class="fb-setting__required"
        aria-hidden="true"
        >*</span
      >
    </label>

    <p
      v-if="setting.instructions"
      :id="`${inputId}-instructions`"
      class="fb-setting__instructions"
    >
      {{ setting.instructions }}
    </p>

    <!-- Text-ish -->
    <input
      v-if="
        setting.type === 'text' ||
        setting.type === 'color' ||
        setting.type === 'date' ||
        setting.type === 'datetime' ||
        setting.type === 'time'
      "
      :id="inputId"
      class="fb-setting__input"
      :type="inputType"
      :value="
        setting.type === 'date' || setting.type === 'datetime'
          ? dateInputValue
          : stringValue
      "
      :aria-describedby="describedBy"
      :aria-invalid="errors?.length ? 'true' : undefined"
      @input="update(($event.target as HTMLInputElement).value)"
    />

    <input
      v-else-if="setting.type === 'handle'"
      :id="inputId"
      class="fb-setting__input fb-setting__input--code"
      type="text"
      spellcheck="false"
      :value="stringValue"
      :aria-describedby="describedBy"
      :aria-invalid="errors?.length ? 'true' : undefined"
      @input="
        handleTouched = true;
        update(($event.target as HTMLInputElement).value);
      "
      @blur="onHandleBlur()"
    />

    <textarea
      v-else-if="setting.type === 'textarea' || setting.type === 'richText'"
      :id="inputId"
      class="fb-setting__input fb-setting__textarea"
      rows="4"
      :value="stringValue"
      :aria-describedby="describedBy"
      :aria-invalid="errors?.length ? 'true' : undefined"
      @input="update(($event.target as HTMLTextAreaElement).value)"
    ></textarea>

    <input
      v-else-if="setting.type === 'number'"
      :id="inputId"
      class="fb-setting__input fb-setting__input--number"
      type="number"
      :value="numberValue"
      :min="setting.min"
      :max="setting.max"
      :aria-describedby="describedBy"
      :aria-invalid="errors?.length ? 'true' : undefined"
      @input="onNumberInput"
    />

    <!-- Boolean -->
    <div v-else-if="setting.type === 'lightswitch'" class="fb-setting__switch">
      <input
        :id="inputId"
        type="checkbox"
        :checked="Boolean(modelValue)"
        :aria-describedby="describedBy"
        @change="update(($event.target as HTMLInputElement).checked)"
      />
      <label :for="inputId">{{ setting.label }}</label>
    </div>

    <!-- Single choice -->
    <select
      v-else-if="
        setting.type === 'select' ||
        setting.type === 'volumeSelect' ||
        setting.type === 'country'
      "
      :id="inputId"
      class="fb-setting__input"
      :value="stringValue"
      :aria-describedby="describedBy"
      :aria-invalid="errors?.length ? 'true' : undefined"
      @change="update(($event.target as HTMLSelectElement).value || null)"
    >
      <!-- A setting with a default is a closed enum - `auto`, `h3`, `vertical` -
           so there is no empty choice to offer, and offering one only invites a
           null the typed PHP property can't take. -->
      <option v-if="setting.default == null" value="">-</option>
      <option
        v-for="option in options"
        :key="String(option.value)"
        :value="String(option.value)"
      >
        {{ option.label }}
      </option>
    </select>

    <!-- Multiple choice -->
    <CheckboxGroupControl
      v-else-if="
        setting.type === 'checkboxGroup' || setting.type === 'elementSources'
      "
      :id="inputId"
      :options="options"
      :model-value="arrayValue"
      @update:model-value="update($event)"
    />

    <!-- Composite editors -->
    <OptionsControl
      v-else-if="setting.type === 'options'"
      :model-value="modelValue"
      :multiple="setting.multiple ?? false"
      @update:model-value="update($event)"
    />

    <SubFieldsControl
      v-else-if="setting.type === 'subFields'"
      :model-value="modelValue"
      :definitions="setting.subFields ?? {}"
      @update:model-value="update($event)"
    />

    <TableColumnsControl
      v-else-if="setting.type === 'tableColumns'"
      :model-value="modelValue"
      @update:model-value="update($event)"
    />

    <p v-else class="fb-setting__unsupported">
      {{ t('No editor for setting type “{type}”.', { type: setting.type }) }}
    </p>

    <p v-if="warning" :id="`${inputId}-warning`" class="fb-setting__warning">
      {{ warning }}
    </p>

    <ul
      v-if="errors?.length"
      :id="`${inputId}-errors`"
      class="fb-setting__errors"
    >
      <li v-for="error in errors" :key="error">{{ error }}</li>
    </ul>
  </div>
</template>
