<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useBuilderStore } from '../stores/builder';
import { t } from '../helpers';
import UpgradePrompt from './UpgradePrompt.vue';
import { TRANSLATABLE_SETTINGS } from '../types';
import type { FieldConfig, SubFieldDefinition } from '../types';

/**
 * The Translations tab: per-site overrides of a form's author-entered
 * strings - page labels, field labels/instructions and whichever other
 * author-facing strings a field type has, choice-field option labels,
 * sub-field and table column labels, and the nine translatable settings
 * messages - resolved server-side by `services/Translations`.
 *
 * Deliberately not a whole-canvas site-switcher (see internal/decisions
 * 0019 and the plan this implements): this is one flat panel scoped to
 * picking a site and editing its overrides, leaving layout and per-site
 * enablement untouched.
 *
 * What a field offers here comes from its palette entry's `translatable`
 * info ({@see PaletteEntry}), not a hardcoded list - a Section or Hidden
 * field has nothing to translate and is skipped, while a Table's columns or
 * a Name field's sub-fields show up automatically.
 */
const store = useBuilderStore();

const isPro = computed(() => store.config?.isPro ?? false);
const sites = computed(() => store.config?.sites ?? []);

const selectedSiteUid = ref<string | null>(sites.value[0]?.uid ?? null);

// The config (and its sites list) can still be null on the first render of
// a fresh boot - keep the selection in step once it lands.
watch(
  sites,
  (list) => {
    if (selectedSiteUid.value === null && list.length > 0) {
      selectedSiteUid.value = list[0].uid;
    }
  },
  { immediate: true },
);

/** Labels for the scalar properties a field type may offer, by key. */
const PROPERTY_LABELS: Record<string, string> = {
  label: t('Label'),
  instructions: t('Instructions'),
  placeholder: t('Placeholder'),
  description: t('Description'),
  addRowLabel: t('Add Row Label'),
  selectionLabel: t('Selection Label'),
  content: t('Content'),
};

function propertyLabel(key: string): string {
  return PROPERTY_LABELS[key] ?? key;
}

/** Every field on the form, in layout order, that has something to translate. */
const fields = computed<FieldConfig[]>(() =>
  store.allFields.filter((field) => {
    const info = store.paletteEntry(field.type)?.translatable;

    return (
      !!info &&
      (info.properties.length > 0 ||
        info.options ||
        info.subFields ||
        info.columns)
    );
  }),
);

function fieldTranslatable(field: FieldConfig) {
  return (
    store.paletteEntry(field.type)?.translatable ?? {
      properties: [],
      options: false,
      subFields: false,
      columns: false,
    }
  );
}

/** A field's own base value for one of its scalar properties. */
function fieldBaseValue(field: FieldConfig, key: string): string {
  const value = field[key];

  return typeof value === 'string' ? value : '';
}

function fieldOverride(fieldId: string, key: string): string {
  if (selectedSiteUid.value === null) {
    return '';
  }

  const override = store.siteTranslations(selectedSiteUid.value).fields[
    fieldId
  ] as Record<string, unknown> | undefined;
  const value = override?.[key];

  return typeof value === 'string' ? value : '';
}

function onFieldChange(fieldId: string, key: string, value: string): void {
  if (selectedSiteUid.value === null) {
    return;
  }

  store.updateFieldTranslation(selectedSiteUid.value, fieldId, {
    [key]: value,
  });
}

interface FieldOptionConfig {
  value: string;
  label: string;
}

/** A choice field's options, or empty for a field that doesn't have any. */
function fieldOptions(field: FieldConfig): FieldOptionConfig[] {
  return Array.isArray(field.options)
    ? (field.options as FieldOptionConfig[])
    : [];
}

/** Mirrors PHP's FieldOption::getValue() - falls back to the label when unset. */
function optionKey(option: FieldOptionConfig): string {
  return option.value !== '' ? option.value : option.label;
}

function optionOverride(fieldId: string, optionValue: string): string {
  if (selectedSiteUid.value === null) {
    return '';
  }

  return (
    store.siteTranslations(selectedSiteUid.value).fields[fieldId]?.options?.[
      optionValue
    ] ?? ''
  );
}

function onOptionChange(
  fieldId: string,
  optionValue: string,
  value: string,
): void {
  if (selectedSiteUid.value === null) {
    return;
  }

  store.updateOptionTranslation(
    selectedSiteUid.value,
    fieldId,
    optionValue,
    value,
  );
}

interface SubFieldRow {
  handle: string;
  label: string;
}

/**
 * A composite field's enabled sub-fields, with the label an override
 * placeholder should show - the author's own override if they set one,
 * otherwise the type's default. Mirrors PHP's `CompositeFormField::getSubFields()`.
 */
function subFieldRows(field: FieldConfig): SubFieldRow[] {
  const schema = store
    .paletteEntry(field.type)
    ?.settingsSchema.find((entry) => entry.name === 'subFieldConfig');
  const defaults = (schema?.subFields ?? {}) as Record<
    string,
    SubFieldDefinition
  >;
  const overrides = (field.subFieldConfig ?? {}) as Record<
    string,
    { enabled?: boolean; label?: string }
  >;
  const rows: SubFieldRow[] = [];

  for (const [handle, definition] of Object.entries(defaults)) {
    const enabled = overrides[handle]?.enabled ?? definition.enabled ?? true;

    if (!enabled) {
      continue;
    }

    rows.push({
      handle,
      label: overrides[handle]?.label ?? definition.label ?? handle,
    });
  }

  return rows;
}

function subFieldOverride(fieldId: string, handle: string): string {
  if (selectedSiteUid.value === null) {
    return '';
  }

  return (
    store.siteTranslations(selectedSiteUid.value).fields[fieldId]?.subFields?.[
      handle
    ] ?? ''
  );
}

function onSubFieldChange(
  fieldId: string,
  handle: string,
  value: string,
): void {
  if (selectedSiteUid.value === null) {
    return;
  }

  store.updateSubFieldTranslation(
    selectedSiteUid.value,
    fieldId,
    handle,
    value,
  );
}

interface ColumnRow {
  handle: string;
  label: string;
}

/** A table's columns are instance data on the field itself, unlike sub-fields. */
function columnRows(field: FieldConfig): ColumnRow[] {
  return Array.isArray(field.columns) ? (field.columns as ColumnRow[]) : [];
}

function columnOverride(fieldId: string, handle: string): string {
  if (selectedSiteUid.value === null) {
    return '';
  }

  return (
    store.siteTranslations(selectedSiteUid.value).fields[fieldId]?.columns?.[
      handle
    ] ?? ''
  );
}

function onColumnChange(fieldId: string, handle: string, value: string): void {
  if (selectedSiteUid.value === null) {
    return;
  }

  store.updateColumnTranslation(selectedSiteUid.value, fieldId, handle, value);
}

/** Every page of the form - the Pages group offers an override for each label. */
const pages = computed(() => store.form.pages);

function pageOverride(pageId: string): string {
  if (selectedSiteUid.value === null) {
    return '';
  }

  return store.siteTranslations(selectedSiteUid.value).pages[pageId] ?? '';
}

function onPageChange(pageId: string, value: string): void {
  if (selectedSiteUid.value === null) {
    return;
  }

  store.updatePageTranslation(selectedSiteUid.value, pageId, value);
}

/** The nine translatable settings, with their base label and current value. */
const translatableSettings = computed(() =>
  TRANSLATABLE_SETTINGS.map((name) => {
    const schema = (store.config?.formSettingsSchema ?? []).find(
      (setting) => setting.name === name,
    );

    return {
      name,
      label: schema?.label ?? name,
      base: String(store.form.settings[name] ?? ''),
    };
  }),
);

function settingOverride(name: string): string {
  if (selectedSiteUid.value === null) {
    return '';
  }

  return store.siteTranslations(selectedSiteUid.value).settings[name] ?? '';
}

function onSettingChange(name: string, value: string): void {
  if (selectedSiteUid.value === null) {
    return;
  }

  store.updateSettingTranslation(selectedSiteUid.value, name, value);
}
</script>

<template>
  <div class="fb-translations">
    <UpgradePrompt v-if="!isPro" :feature="t('Translations')">
      {{
        t(
          'Overriding page labels, field strings, options and messages per site is a Formable Pro feature.',
        )
      }}
    </UpgradePrompt>

    <div v-else class="fb-settings">
      <section class="fb-settings__group">
        <div class="fb-setting">
          <label class="fb-setting__label" for="fb-translations-site">{{
            t('Site')
          }}</label>
          <p class="fb-setting__instructions">
            {{
              t(
                'Overrides apply only to the selected site; every other site keeps rendering the base text.',
              )
            }}
          </p>
          <select
            id="fb-translations-site"
            v-model="selectedSiteUid"
            class="fb-setting__input"
          >
            <option v-for="site in sites" :key="site.uid" :value="site.uid">
              {{ site.name }}
            </option>
          </select>
        </div>
      </section>

      <section v-if="pages.length > 1" class="fb-settings__group">
        <h2 class="fb-settings__heading">{{ t('Pages') }}</h2>

        <div v-for="(page, index) in pages" :key="page.id" class="fb-setting">
          <label class="fb-setting__label" :for="`fb-tr-page-${page.id}`">{{
            page.label || t('Page {number}', { number: index + 1 })
          }}</label>
          <input
            :id="`fb-tr-page-${page.id}`"
            class="fb-setting__input"
            type="text"
            :placeholder="page.label"
            :value="pageOverride(page.id)"
            @input="
              onPageChange(page.id, ($event.target as HTMLInputElement).value)
            "
          />
        </div>
      </section>

      <section v-if="fields.length" class="fb-settings__group">
        <h2 class="fb-settings__heading">{{ t('Fields') }}</h2>

        <div
          v-for="field in fields"
          :key="field.id"
          class="fb-translations__field"
        >
          <h3 class="fb-translations__field-name">
            {{ field.label || field.handle }}
          </h3>

          <div
            v-for="property in fieldTranslatable(field).properties"
            :key="property"
            class="fb-setting"
          >
            <label
              class="fb-setting__label"
              :for="`fb-tr-${property}-${field.id}`"
              >{{ propertyLabel(property) }}</label
            >
            <input
              :id="`fb-tr-${property}-${field.id}`"
              class="fb-setting__input"
              type="text"
              :placeholder="fieldBaseValue(field, property)"
              :value="fieldOverride(field.id, property)"
              @input="
                onFieldChange(
                  field.id,
                  property,
                  ($event.target as HTMLInputElement).value,
                )
              "
            />
          </div>

          <div
            v-if="
              fieldTranslatable(field).options && fieldOptions(field).length
            "
            class="fb-translations__nested"
          >
            <div
              v-for="option in fieldOptions(field)"
              :key="optionKey(option)"
              class="fb-setting"
            >
              <label
                class="fb-setting__label"
                :for="`fb-tr-option-${field.id}-${optionKey(option)}`"
                >{{ option.label }}</label
              >
              <input
                :id="`fb-tr-option-${field.id}-${optionKey(option)}`"
                class="fb-setting__input"
                type="text"
                :placeholder="option.label"
                :value="optionOverride(field.id, optionKey(option))"
                @input="
                  onOptionChange(
                    field.id,
                    optionKey(option),
                    ($event.target as HTMLInputElement).value,
                  )
                "
              />
            </div>
          </div>

          <div
            v-if="
              fieldTranslatable(field).subFields && subFieldRows(field).length
            "
            class="fb-translations__nested"
          >
            <div
              v-for="row in subFieldRows(field)"
              :key="row.handle"
              class="fb-setting"
            >
              <label
                class="fb-setting__label"
                :for="`fb-tr-subfield-${field.id}-${row.handle}`"
                >{{ row.label }}</label
              >
              <input
                :id="`fb-tr-subfield-${field.id}-${row.handle}`"
                class="fb-setting__input"
                type="text"
                :placeholder="row.label"
                :value="subFieldOverride(field.id, row.handle)"
                @input="
                  onSubFieldChange(
                    field.id,
                    row.handle,
                    ($event.target as HTMLInputElement).value,
                  )
                "
              />
            </div>
          </div>

          <div
            v-if="fieldTranslatable(field).columns && columnRows(field).length"
            class="fb-translations__nested"
          >
            <div
              v-for="column in columnRows(field)"
              :key="column.handle"
              class="fb-setting"
            >
              <label
                class="fb-setting__label"
                :for="`fb-tr-column-${field.id}-${column.handle}`"
                >{{ column.label }}</label
              >
              <input
                :id="`fb-tr-column-${field.id}-${column.handle}`"
                class="fb-setting__input"
                type="text"
                :placeholder="column.label"
                :value="columnOverride(field.id, column.handle)"
                @input="
                  onColumnChange(
                    field.id,
                    column.handle,
                    ($event.target as HTMLInputElement).value,
                  )
                "
              />
            </div>
          </div>
        </div>
      </section>

      <section class="fb-settings__group">
        <h2 class="fb-settings__heading">{{ t('Messages & buttons') }}</h2>

        <div
          v-for="setting in translatableSettings"
          :key="setting.name"
          class="fb-setting"
        >
          <label
            class="fb-setting__label"
            :for="`fb-tr-setting-${setting.name}`"
            >{{ setting.label }}</label
          >
          <input
            :id="`fb-tr-setting-${setting.name}`"
            class="fb-setting__input"
            type="text"
            :placeholder="setting.base"
            :value="settingOverride(setting.name)"
            @input="
              onSettingChange(
                setting.name,
                ($event.target as HTMLInputElement).value,
              )
            "
          />
        </div>
      </section>
    </div>
  </div>
</template>

<style scoped>
.fb-translations__field {
  border-top: 1px solid #eef0f2;
  padding-top: 12px;
  margin-top: 12px;
}

.fb-translations__field:first-child {
  border-top: none;
  padding-top: 0;
  margin-top: 0;
}

.fb-translations__field-name {
  font-size: 13px;
  font-weight: 600;
  margin: 0 0 8px;
}

.fb-translations__nested {
  margin-left: 16px;
  padding-left: 12px;
  border-left: 2px solid #eef0f2;
}
</style>
