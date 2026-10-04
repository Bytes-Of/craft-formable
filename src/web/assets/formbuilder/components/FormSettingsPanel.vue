<script setup lang="ts">
import { computed } from 'vue';
import { useBuilderStore } from '../stores/builder';
import { matchesWhen, t } from '../helpers';
import SettingsControl from './SettingsControl.vue';
import UpgradePrompt from './UpgradePrompt.vue';
import type { SettingSchema } from '../types';

const store = useBuilderStore();

/** Settings schema split into its declared groups, in first-seen order. */
const groups = computed(() => {
  const byGroup = new Map<string, SettingSchema[]>();

  (store.config?.formSettingsSchema ?? [])
    .filter(isVisible)
    .forEach((setting) => {
      const group = setting.group ?? 'general';
      byGroup.set(group, [...(byGroup.get(group) ?? []), setting]);
    });

  return [...byGroup.entries()].map(([id, settings]) => ({
    id,
    label: groupLabel(id),
    settings,
  }));
});

function groupLabel(id: string): string {
  switch (id) {
    case 'appearance':
      return t('Appearance');
    case 'availability':
      return t('Availability');
    case 'pages':
      return t('Pages & navigation');
    case 'privacy':
      return t('Privacy & data');
    case 'spam':
      return t('Spam protection');
    default:
      return t('General');
  }
}

/** Pro-only settings render locked, behind an upgrade prompt, in Lite. */
function isLocked(setting: SettingSchema): boolean {
  return Boolean(setting.proOnly) && !store.isPro;
}

function isVisible(setting: SettingSchema): boolean {
  if (!setting.when) {
    return true;
  }

  return Object.entries(setting.when).every(([name, expected]) =>
    matchesWhen(store.form.settings[name], expected),
  );
}

function valueFor(setting: SettingSchema): unknown {
  return store.form.settings[setting.name] ?? setting.default ?? null;
}

function onChange(setting: SettingSchema, value: unknown): void {
  store.form.settings = { ...store.form.settings, [setting.name]: value };
}

/**
 * A form that stores nothing can't deliver to an integration. Turning storage
 * off while one is switched on is refused on save; this says so beforehand,
 * naming what to switch off - the server enforces it, the warning only spares
 * the author a failed save.
 */
function warningFor(setting: SettingSchema): string | null {
  if (
    setting.name !== 'storeSubmissions' ||
    store.storesSubmissions ||
    store.switchedOnIntegrations.length === 0
  ) {
    return null;
  }

  return t(
    'Integrations only run for stored submissions. Turn this back on, or switch off: {names}.',
    {
      names: store.switchedOnIntegrations
        .map((integration) => integration.name)
        .join(', '),
    },
  );
}

const statusOptions = computed(() => store.config?.statuses ?? []);
</script>

<template>
  <div class="fb-settings">
    <section class="fb-settings__group">
      <h2 class="fb-settings__heading">{{ t('Form') }}</h2>

      <div class="fb-setting">
        <label class="fb-setting__label" for="fb-enabled">{{
          t('Enabled')
        }}</label>
        <p class="fb-setting__instructions">
          {{ t('Disabled forms return a closed message instead of the form.') }}
        </p>
        <div class="fb-setting__switch">
          <input id="fb-enabled" v-model="store.form.enabled" type="checkbox" />
          <label for="fb-enabled">{{ t('Accept submissions') }}</label>
        </div>
      </div>

      <div class="fb-setting">
        <label class="fb-setting__label" for="fb-status">{{
          t('Default submission status')
        }}</label>
        <select
          id="fb-status"
          class="fb-setting__input"
          :value="store.form.defaultStatusId ?? ''"
          @change="
            store.form.defaultStatusId =
              ($event.target as HTMLSelectElement).value === ''
                ? null
                : Number(($event.target as HTMLSelectElement).value)
          "
        >
          <option value="">-</option>
          <option
            v-for="status in statusOptions"
            :key="String(status.value)"
            :value="status.value"
          >
            {{ status.label }}
          </option>
        </select>
      </div>
    </section>

    <section v-for="group in groups" :key="group.id" class="fb-settings__group">
      <h2 class="fb-settings__heading">{{ group.label }}</h2>

      <template v-for="setting in group.settings" :key="setting.name">
        <div v-if="isLocked(setting)" class="fb-setting">
          <span class="fb-setting__label">{{ setting.label }}</span>
          <p v-if="setting.instructions" class="fb-setting__instructions">
            {{ setting.instructions }}
          </p>
          <UpgradePrompt :feature="setting.label" />
        </div>

        <SettingsControl
          v-else
          :setting="setting"
          :model-value="valueFor(setting)"
          :errors="store.settingsErrors[setting.name] ?? []"
          :warning="warningFor(setting)"
          @update:model-value="onChange(setting, $event)"
        />
      </template>
    </section>
  </div>
</template>
