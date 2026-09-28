<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useBuilderStore } from '../stores/builder';
import SettingsControl from './SettingsControl.vue';
import ConditionsEditor from './ConditionsEditor.vue';
import { matchesWhen, t } from '../helpers';
import {
  emptyConditionSet,
  type ConditionSet,
  type SettingSchema,
} from '../types';

const store = useBuilderStore();

const field = computed(() => store.selectedField);
const entry = computed(() =>
  field.value ? store.paletteEntry(field.value.type) : undefined,
);

const titleRef = ref<HTMLHeadingElement | null>(null);

/**
 * A field card's click swaps the drawer's content in place without moving
 * focus, so a screen reader user hears nothing. Move focus onto the new
 * heading whenever the selection changes to a field - not on close, where
 * `close()` below sends focus back to the card instead.
 */
watch(
  () => store.selectedFieldId,
  (id) => {
    if (id) {
      titleRef.value?.focus();
    }
  },
  { flush: 'post' },
);

function close(): void {
  const cardId = field.value?.id;

  store.selectedFieldId = null;

  if (cardId) {
    document.getElementById(`fb-field-${cardId}`)?.focus();
  }
}

/** Only the settings whose `when` conditions currently hold. */
const visibleSchema = computed<SettingSchema[]>(() =>
  store.selectedFieldSchema.filter((setting) => isVisible(setting)),
);

/**
 * Visible settings split into their declared groups, in first-seen order.
 * Ungrouped settings (the field's core settings - label, handle, etc.) render
 * under no heading; `appearance` and `advanced` get one, same as
 * FormSettingsPanel does for form-level settings.
 */
const groupedSchema = computed(() => {
  const byGroup = new Map<string, SettingSchema[]>();

  visibleSchema.value.forEach((setting) => {
    const group = setting.group ?? 'general';
    byGroup.set(group, [...(byGroup.get(group) ?? []), setting]);
  });

  return [...byGroup.entries()].map(([id, settings]) => ({
    id,
    label: id === 'general' ? null : groupLabel(id),
    settings,
  }));
});

function groupLabel(id: string): string {
  switch (id) {
    case 'appearance':
      return t('Appearance');
    case 'advanced':
      return t('Advanced');
    default:
      return t('General');
  }
}

function isVisible(setting: SettingSchema): boolean {
  if (!setting.when || !field.value) {
    return true;
  }

  return Object.entries(setting.when).every(([name, expected]) =>
    matchesWhen(field.value?.[name], expected),
  );
}

function valueFor(setting: SettingSchema): unknown {
  return field.value?.[setting.name] ?? setting.default ?? null;
}

function onChange(setting: SettingSchema, value: unknown): void {
  if (!field.value) {
    return;
  }

  store.updateField(field.value.id, { [setting.name]: value });
}

const errorsFor = (name: string): string[] =>
  field.value ? (store.errorsForField(field.value.id)[name] ?? []) : [];

const fieldConditions = computed<ConditionSet>(
  () => field.value?.conditions ?? emptyConditionSet(),
);

function onConditionsChange(value: ConditionSet): void {
  if (field.value) {
    store.updateField(field.value.id, { conditions: value });
  }
}

/**
 * Warns about a handle collision as it's typed. The server enforces this too;
 * this only saves the author a failed save.
 */
const handleWarning = computed<string | null>(() => {
  const handle = field.value?.handle;

  if (!handle || (store.usedHandles[handle] ?? 0) < 2) {
    return null;
  }

  return t('“{handle}” is used by more than one field on this form.', {
    handle,
  });
});
</script>

<template>
  <aside class="fb-drawer" :aria-label="t('Field settings')">
    <div v-if="!field" class="fb-drawer__empty">
      <p>{{ t('Select a field to edit its settings.') }}</p>
    </div>

    <template v-else>
      <header class="fb-drawer__header">
        <h2 ref="titleRef" class="fb-drawer__title" tabindex="-1">
          {{ entry?.name ?? field.type }}
        </h2>
        <button
          type="button"
          class="fb-drawer__close"
          :aria-label="t('Close')"
          @click="close"
        >
          ×
        </button>
      </header>

      <div class="fb-drawer__body">
        <section
          v-for="group in groupedSchema"
          :key="group.id"
          class="fb-settings__group"
        >
          <h3 v-if="group.label" class="fb-settings__heading">
            {{ group.label }}
          </h3>

          <SettingsControl
            v-for="setting in group.settings"
            :key="setting.name"
            :setting="setting"
            :model-value="valueFor(setting)"
            :errors="errorsFor(setting.name)"
            :warning="setting.name === 'handle' ? handleWarning : null"
            :source-value="
              setting.sourceField ? field[setting.sourceField] : undefined
            "
            :field-id="field.id"
            @update:model-value="onChange(setting, $event)"
          />
        </section>

        <section class="fb-drawer__section">
          <h3 class="fb-drawer__section-title">{{ t('Conditions') }}</h3>
          <p class="fb-drawer__section-help">
            {{
              t(
                'Show or hide this field based on what’s been answered elsewhere on the form.',
              )
            }}
          </p>
          <ConditionsEditor
            :model-value="fieldConditions"
            :own-field-id="field.id"
            @update:model-value="onConditionsChange"
          />
        </section>
      </div>
    </template>
  </aside>
</template>
