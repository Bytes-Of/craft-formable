<script setup lang="ts">
import { computed, ref } from 'vue';
import { useBuilderStore } from '../stores/builder';
import { t } from '../helpers';
import ConditionsEditor from './ConditionsEditor.vue';
import UpgradePrompt from './UpgradePrompt.vue';
import type { ConditionSet, IntegrationDefinition } from '../types';

/**
 * The Integrations tab: switches on any of the globally-configured integrations
 * per form, maps the form's fields onto the integration's targets, and gates
 * forwarding behind conditional rules - the same engine the notifications and
 * fields use.
 *
 * Global connection setup (credentials, OAuth) lives in the CP settings area,
 * not here; this tab only ever references integrations an admin already created.
 */
const store = useBuilderStore();

const integrations = computed<IntegrationDefinition[]>(
  () => store.config?.integrations ?? [],
);
const log = computed(() => store.config?.integrationLog ?? []);
const isPro = computed(() => store.config?.isPro ?? false);
const integrationsUrl = computed(() => store.config?.integrationsUrl ?? '');

const selectedHandle = computed<string | null>(
  () => store.selectedIntegrationHandle,
);
const selected = computed<IntegrationDefinition | null>(
  () =>
    integrations.value.find(
      (integration) => integration.handle === selectedHandle.value,
    ) ?? null,
);

/** Form fields the author can map onto a target - those that hold a value. */
const fieldOptions = computed(() =>
  store.allFields
    .filter((field) => typeof field.handle === 'string' && field.handle !== '')
    .map((field) => ({
      value: field.handle,
      label: field.label || field.handle,
    })),
);

function config(handle: string) {
  return store.integrationConfig(handle);
}

function isEnabled(handle: string): boolean {
  return config(handle).enabled;
}

/**
 * With storage off there's nothing to deliver, so a switched-off integration
 * can't be turned on. One that's already on stays switchable - a form saved
 * with the combination before it was refused has to be fixable from here.
 */
function canSwitchOn(handle: string): boolean {
  return store.storesSubmissions || isEnabled(handle);
}

/**
 * Turning an integration off doesn't drop its mapping or conditions - they
 * stay saved, unlike deleting a field or a notification - but it does stop
 * real submissions from reaching it from this point on, silently and
 * immediately. That's the thing worth a misclick guard.
 */
function toggle(handle: string, enabled: boolean): void {
  if (!enabled) {
    const integration = integrations.value.find((i) => i.handle === handle);

    if (
      !window.confirm(
        t('Stop forwarding submissions to “{name}”?', {
          name: integration?.name ?? handle,
        }),
      )
    ) {
      return;
    }
  }

  store.updateIntegration(handle, { enabled });

  if (enabled) {
    store.selectedIntegrationHandle = handle;
  }
}

function select(handle: string): void {
  store.selectedIntegrationHandle = handle;
}

function mappedValue(handle: string, target: string): string {
  return config(handle).values[target] ?? '';
}

function onMap(handle: string, target: string, source: string): void {
  store.mapIntegrationField(handle, target, source);
}

function updateConditions(handle: string, value: ConditionSet): void {
  store.updateIntegration(handle, { conditions: value });
}

const resendingId = ref<number | null>(null);
const resendMessage = ref<{ ok: boolean; text: string } | null>(null);

async function resend(logId: number): Promise<void> {
  if (resendingId.value !== null) {
    return;
  }

  resendingId.value = logId;
  resendMessage.value = null;

  const result = await store.resendIntegration(logId);

  resendingId.value = null;
  resendMessage.value = { ok: result.success, text: result.message };
}

// Open on the first switched-on integration, else the first available one.
if (selectedHandle.value === null && integrations.value.length > 0) {
  const enabled = integrations.value.find((integration) =>
    isEnabled(integration.handle),
  );
  store.selectedIntegrationHandle = (enabled ?? integrations.value[0]).handle;
}
</script>

<template>
  <div class="fb-integrations">
    <UpgradePrompt v-if="!isPro" :feature="t('Integrations')">
      {{
        t(
          'Integrations are a Pro feature. Configure them here, then upgrade to start forwarding submissions.',
        )
      }}
    </UpgradePrompt>

    <div v-if="!store.storesSubmissions" class="fb-integrations__notice">
      <p>
        {{
          t(
            'This form doesn’t store submissions, so integrations can’t run - they deliver a saved submission.',
          )
        }}
        <template v-if="store.switchedOnIntegrations.length">
          {{
            t('The form can’t be saved while these are switched on: {names}.', {
              names: store.switchedOnIntegrations
                .map((integration) => integration.name)
                .join(', '),
            })
          }}
        </template>
      </p>
      <button
        type="button"
        class="btn small"
        @click="store.activeTab = 'settings'"
      >
        {{ t('Open settings') }}
      </button>
    </div>

    <div v-if="!integrations.length" class="fb-integrations__empty">
      <p>{{ t('No integrations are set up yet.') }}</p>
      <p>
        <a
          v-if="integrationsUrl"
          class="go"
          :href="integrationsUrl"
          target="_blank"
          rel="noopener"
        >
          {{ t('Add one in Formable’s integration settings') }}
        </a>
      </p>
    </div>

    <div v-else class="fb-integrations__body">
      <aside class="fb-integrations__list">
        <h2 class="fb-integrations__list-title">{{ t('Available') }}</h2>
        <ul class="fb-integrations__items">
          <li
            v-for="integration in integrations"
            :key="integration.handle"
            class="fb-integrations__item"
            :class="{
              'fb-integrations__item--active':
                integration.handle === selectedHandle,
            }"
          >
            <button
              type="button"
              class="fb-integrations__item-select"
              @click="select(integration.handle)"
            >
              <span class="fb-integrations__item-name">{{
                integration.name
              }}</span>
              <span
                class="fb-integrations__item-state"
                :class="{
                  'fb-integrations__item-state--off': !isEnabled(
                    integration.handle,
                  ),
                }"
              >
                {{ isEnabled(integration.handle) ? t('On') : t('Off') }}
              </span>
            </button>
          </li>
        </ul>
      </aside>

      <section v-if="selected" class="fb-integrations__editor fb-settings">
        <div class="fb-settings__group">
          <div class="fb-setting">
            <label class="fb-setting__label">{{ selected.name }}</label>
            <div class="fb-setting__switch">
              <input
                :id="`fb-int-${selected.handle}`"
                type="checkbox"
                :checked="isEnabled(selected.handle)"
                :disabled="!canSwitchOn(selected.handle)"
                @change="
                  toggle(
                    selected.handle,
                    ($event.target as HTMLInputElement).checked,
                  )
                "
              />
              <label :for="`fb-int-${selected.handle}`">{{
                t('Send submissions to this integration')
              }}</label>
            </div>
          </div>
        </div>

        <div v-if="isEnabled(selected.handle)" class="fb-integrations__config">
          <div v-if="selected.mappableFields.length" class="fb-settings__group">
            <h2 class="fb-settings__heading">{{ t('Field mapping') }}</h2>
            <p class="fb-setting__instructions">
              {{
                t(
                  'Choose which form field fills each target. Leave a target unmapped to skip it.',
                )
              }}
            </p>

            <div
              v-for="target in selected.mappableFields"
              :key="target.handle"
              class="fb-setting"
            >
              <label
                class="fb-setting__label"
                :for="`fb-map-${selected.handle}-${target.handle}`"
              >
                {{ target.name }}
                <span
                  v-if="target.required"
                  class="fb-integrations__req"
                  :title="t('Required')"
                  >*</span
                >
              </label>
              <select
                :id="`fb-map-${selected.handle}-${target.handle}`"
                class="fb-setting__input"
                :value="mappedValue(selected.handle, target.handle)"
                @change="
                  onMap(
                    selected.handle,
                    target.handle,
                    ($event.target as HTMLSelectElement).value,
                  )
                "
              >
                <option value="">{{ t('- Not mapped -') }}</option>
                <option
                  v-for="option in fieldOptions"
                  :key="option.value"
                  :value="option.value"
                >
                  {{ option.label }}
                </option>
              </select>
            </div>
          </div>

          <div v-else class="fb-settings__group">
            <p class="fb-setting__instructions">
              {{
                t(
                  'This integration receives the whole submission - there are no fields to map.',
                )
              }}
            </p>
          </div>

          <div class="fb-settings__group">
            <h2 class="fb-settings__heading">{{ t('Conditions') }}</h2>
            <p class="fb-setting__instructions">
              {{
                t(
                  'Only forward to this integration when the submission matches these rules.',
                )
              }}
            </p>
            <ConditionsEditor
              :model-value="config(selected.handle).conditions"
              @update:model-value="updateConditions(selected.handle, $event)"
            />
          </div>
        </div>
      </section>
    </div>

    <div v-if="log.length" class="fb-integrations__log">
      <h2 class="fb-integrations__log-title">{{ t('Recent deliveries') }}</h2>
      <!-- The live region stays mounted so the message is announced when it
           appears, rather than arriving inside a node that was just inserted. -->
      <div role="status">
        <p
          v-if="resendMessage"
          class="fb-integrations__resend-result"
          :class="{
            'fb-integrations__resend-result--error': !resendMessage.ok,
          }"
        >
          {{ resendMessage.text }}
        </p>
      </div>
      <table class="fb-integrations__log-table">
        <thead>
          <tr>
            <th>{{ t('Status') }}</th>
            <th>{{ t('Integration') }}</th>
            <th>{{ t('Detail') }}</th>
            <th>{{ t('Submission') }}</th>
            <th>
              <span class="visually-hidden">{{ t('Actions') }}</span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="entry in log" :key="entry.id">
            <td>
              <span
                class="fb-integrations__log-status"
                :class="
                  entry.success
                    ? 'fb-integrations__log-status--ok'
                    : 'fb-integrations__log-status--fail'
                "
              >
                {{ entry.success ? t('Sent') : t('Failed') }}
              </span>
            </td>
            <td>{{ entry.integration }}</td>
            <td>{{ entry.message || '-' }}</td>
            <td>
              <a
                v-if="entry.submissionUrl"
                :href="entry.submissionUrl"
                target="_blank"
                rel="noopener"
                >{{ entry.date ?? t('View') }}</a
              >
              <template v-else>{{ entry.date ?? '-' }}</template>
            </td>
            <td class="fb-integrations__log-action">
              <button
                v-if="entry.canResend"
                type="button"
                class="btn small"
                :aria-label="
                  t('Resend to {integration}', {
                    integration: entry.integration,
                  })
                "
                :disabled="resendingId !== null"
                @click="resend(entry.id)"
              >
                {{ resendingId === entry.id ? t('Resending…') : t('Resend') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<style scoped>
.fb-integrations__notice {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;
  padding: 10px 12px;
  border: 1px solid var(--warning-color, #d4762a);
  border-radius: 5px;
  font-size: 13px;
  line-height: 1.5;
}

.fb-integrations__notice p {
  margin: 0;
}

.fb-integrations__empty {
  color: #606d7b;
  font-size: 13px;
  line-height: 1.6;
}

.fb-integrations__body {
  display: grid;
  grid-template-columns: minmax(200px, 260px) 1fr;
  gap: 24px;
  align-items: start;
}

.fb-integrations__list-title {
  font-size: 14px;
  font-weight: 600;
  margin: 0 0 12px;
}

.fb-integrations__items {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.fb-integrations__item {
  border: 1px solid #e3e5e8;
  border-radius: 5px;
  overflow: hidden;
}

.fb-integrations__item--active {
  border-color: #4a7cf6;
  box-shadow: 0 0 0 1px #4a7cf6;
}

.fb-integrations__item-select {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 8px 10px;
  background: none;
  border: none;
  cursor: pointer;
  text-align: left;
  font: inherit;
}

.fb-integrations__item-name {
  font-size: 13px;
  font-weight: 500;
}

.fb-integrations__item-state {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #3f9142;
}

.fb-integrations__item-state--off {
  color: #9aa5b1;
}

.fb-integrations__req {
  color: #cf1124;
}

.fb-integrations__log {
  margin-top: 28px;
  border-top: 1px solid #e3e5e8;
  padding-top: 16px;
}

.fb-integrations__log-title {
  font-size: 14px;
  font-weight: 600;
  margin: 0 0 12px;
}

.fb-integrations__log-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.fb-integrations__log-table th,
.fb-integrations__log-table td {
  text-align: left;
  padding: 6px 12px 6px 0;
  border-bottom: 1px solid #eef0f2;
  vertical-align: top;
}

.fb-integrations__log-table th {
  color: #606d7b;
  font-weight: 500;
}

.fb-integrations__log-status--ok {
  color: #3f9142;
}

.fb-integrations__log-status--fail {
  color: #cf1124;
}

.fb-integrations__log-table .fb-integrations__log-action {
  padding-right: 0;
  text-align: right;
  white-space: nowrap;
}

.fb-integrations__resend-result {
  margin: 0 0 12px;
  font-size: 13px;
  color: #3f9142;
}

.fb-integrations__resend-result--error {
  color: #cf1124;
}
</style>
