<script setup lang="ts">
import { computed, ref } from 'vue';
import { useBuilderStore } from '../stores/builder';
import { t } from '../helpers';
import ConditionsEditor from './ConditionsEditor.vue';
import type { ConditionSet, Notification } from '../types';

/**
 * The Notifications tab: a list of the form's email notifications and an
 * editor for the selected one.
 *
 * Every address and the subject/body are authored as templates - the token
 * reference lists the field handles available, and a test send renders the
 * whole thing against sample values so an author can see the result before a
 * real submission ever arrives.
 */
const store = useBuilderStore();

const notifications = computed<Notification[]>(() => store.form.notifications);
const selectedIndex = computed<number | null>(
  () => store.selectedNotificationIndex,
);
const selected = computed<Notification | null>(() =>
  selectedIndex.value !== null
    ? (notifications.value[selectedIndex.value] ?? null)
    : null,
);

const tokens = computed(() => store.config?.emailTokens ?? []);
const log = computed(() => store.config?.notificationLog ?? []);

const testTo = ref(store.config?.notificationDefaults.testTo ?? '');
const testing = ref(false);
const testMessage = ref<{ ok: boolean; text: string } | null>(null);

function errorsFor(index: number): Record<string, string[]> {
  return store.notificationErrorsFor(index);
}

function hasErrors(index: number): boolean {
  return Object.keys(errorsFor(index)).length > 0;
}

function update(changes: Partial<Notification>): void {
  if (selectedIndex.value !== null) {
    store.updateNotification(selectedIndex.value, changes);
  }
}

function updateConditions(value: ConditionSet): void {
  update({ conditions: value });
}

function select(index: number): void {
  store.selectedNotificationIndex = index;
  testMessage.value = null;
}

// Open on the first notification rather than an empty pane when the tab is
// entered on a form that already has some.
if (selectedIndex.value === null && notifications.value.length > 0) {
  store.selectedNotificationIndex = 0;
}

function remove(index: number): void {
  const name = notifications.value[index]?.name || t('this notification');

  if (!window.confirm(t('Delete “{name}”?', { name }))) {
    return;
  }

  store.removeNotification(index);
}

async function sendTest(): Promise<void> {
  if (selectedIndex.value === null || testing.value) {
    return;
  }

  testing.value = true;
  testMessage.value = null;

  const result = await store.testNotification(
    selectedIndex.value,
    testTo.value,
  );

  testing.value = false;
  testMessage.value = { ok: result.success, text: result.message };
}

const resendingId = ref<number | null>(null);
const resendMessage = ref<{ ok: boolean; text: string } | null>(null);

async function resend(logId: number): Promise<void> {
  if (resendingId.value !== null) {
    return;
  }

  resendingId.value = logId;
  resendMessage.value = null;

  const result = await store.resendNotification(logId);

  resendingId.value = null;
  resendMessage.value = { ok: result.success, text: result.message };
}

/** The current error for a field on the selected notification, if any. */
function fieldError(name: string): string | null {
  if (selectedIndex.value === null) {
    return null;
  }

  return errorsFor(selectedIndex.value)[name]?.[0] ?? null;
}
</script>

<template>
  <div class="fb-notifs">
    <aside class="fb-notifs__list">
      <div class="fb-notifs__list-head">
        <h2 class="fb-notifs__list-title">{{ t('Notifications') }}</h2>
        <button
          type="button"
          class="btn small"
          @click="store.addNotification()"
        >
          {{ t('Add notification') }}
        </button>
      </div>

      <p v-if="!notifications.length" class="fb-notifs__empty">
        {{
          t(
            'No notifications yet. Add one to email someone when this form is submitted.',
          )
        }}
      </p>

      <ul v-else class="fb-notifs__items">
        <li
          v-for="(notification, index) in notifications"
          :key="index"
          class="fb-notifs__item"
          :class="{
            'fb-notifs__item--active': index === selectedIndex,
            'fb-notifs__item--error': hasErrors(index),
          }"
        >
          <button
            type="button"
            class="fb-notifs__item-select"
            @click="select(index)"
          >
            <span class="fb-notifs__item-name">{{
              notification.name || t('Untitled notification')
            }}</span>
            <span
              class="fb-notifs__item-state"
              :class="{ 'fb-notifs__item-state--off': !notification.enabled }"
            >
              {{ notification.enabled ? t('On') : t('Off') }}
            </span>
          </button>
          <button
            type="button"
            class="fb-notifs__item-remove"
            :aria-label="t('Delete')"
            @click="remove(index)"
          >
            ×
          </button>
        </li>
      </ul>
    </aside>

    <section v-if="selected" class="fb-notifs__editor fb-settings">
      <div class="fb-settings__group">
        <div
          class="fb-setting"
          :class="{ 'fb-setting--error': fieldError('name') }"
        >
          <label class="fb-setting__label" for="fb-notif-name">{{
            t('Name')
          }}</label>
          <input
            id="fb-notif-name"
            class="fb-setting__input"
            type="text"
            :value="selected.name"
            @input="update({ name: ($event.target as HTMLInputElement).value })"
          />
          <ul v-if="fieldError('name')" class="fb-setting__errors">
            <li>{{ fieldError('name') }}</li>
          </ul>
        </div>

        <div class="fb-setting">
          <label class="fb-setting__label">{{ t('Enabled') }}</label>
          <div class="fb-setting__switch">
            <input
              id="fb-notif-enabled"
              type="checkbox"
              :checked="selected.enabled"
              @change="
                update({ enabled: ($event.target as HTMLInputElement).checked })
              "
            />
            <label for="fb-notif-enabled">{{
              t('Send this notification')
            }}</label>
          </div>
        </div>
      </div>

      <div class="fb-settings__group">
        <h2 class="fb-settings__heading">{{ t('Recipients') }}</h2>

        <div
          class="fb-setting"
          :class="{ 'fb-setting--error': fieldError('recipients') }"
        >
          <label class="fb-setting__label" for="fb-notif-to">{{
            t('To')
          }}</label>
          <p class="fb-setting__instructions">
            {{
              t(
                'Comma-separated. Twig and field tokens are supported - see the token list below.',
              )
            }}
          </p>
          <input
            id="fb-notif-to"
            class="fb-setting__input"
            type="text"
            :value="selected.recipients"
            @input="
              update({ recipients: ($event.target as HTMLInputElement).value })
            "
          />
          <ul v-if="fieldError('recipients')" class="fb-setting__errors">
            <li>{{ fieldError('recipients') }}</li>
          </ul>
        </div>

        <div class="fb-setting">
          <label class="fb-setting__label" for="fb-notif-cc">{{
            t('CC')
          }}</label>
          <input
            id="fb-notif-cc"
            class="fb-setting__input"
            type="text"
            :value="selected.cc"
            @input="update({ cc: ($event.target as HTMLInputElement).value })"
          />
        </div>

        <div class="fb-setting">
          <label class="fb-setting__label" for="fb-notif-bcc">{{
            t('BCC')
          }}</label>
          <input
            id="fb-notif-bcc"
            class="fb-setting__input"
            type="text"
            :value="selected.bcc"
            @input="update({ bcc: ($event.target as HTMLInputElement).value })"
          />
        </div>

        <div class="fb-setting">
          <label class="fb-setting__label" for="fb-notif-replyto">{{
            t('Reply-To')
          }}</label>
          <input
            id="fb-notif-replyto"
            class="fb-setting__input"
            type="text"
            :value="selected.replyTo"
            @input="
              update({ replyTo: ($event.target as HTMLInputElement).value })
            "
          />
        </div>
      </div>

      <div class="fb-settings__group">
        <h2 class="fb-settings__heading">{{ t('Sender') }}</h2>

        <div class="fb-setting">
          <label class="fb-setting__label" for="fb-notif-fromname">{{
            t('From Name')
          }}</label>
          <input
            id="fb-notif-fromname"
            class="fb-setting__input"
            type="text"
            :value="selected.fromName"
            @input="
              update({ fromName: ($event.target as HTMLInputElement).value })
            "
          />
        </div>

        <div class="fb-setting">
          <label class="fb-setting__label" for="fb-notif-fromemail">{{
            t('From Email')
          }}</label>
          <p class="fb-setting__instructions">
            {{ t('Leave blank to use the site’s default sender.') }}
          </p>
          <input
            id="fb-notif-fromemail"
            class="fb-setting__input"
            type="text"
            :value="selected.fromEmail"
            @input="
              update({ fromEmail: ($event.target as HTMLInputElement).value })
            "
          />
        </div>
      </div>

      <div class="fb-settings__group">
        <h2 class="fb-settings__heading">{{ t('Content') }}</h2>

        <div class="fb-setting">
          <label class="fb-setting__label" for="fb-notif-subject">{{
            t('Subject')
          }}</label>
          <input
            id="fb-notif-subject"
            class="fb-setting__input"
            type="text"
            :value="selected.subject"
            @input="
              update({ subject: ($event.target as HTMLInputElement).value })
            "
          />
        </div>

        <div class="fb-setting">
          <label class="fb-setting__label" for="fb-notif-body">{{
            t('Body')
          }}</label>
          <p class="fb-setting__instructions">
            {{
              t(
                'HTML and Twig are supported. The body is sent inside your email template; leave it blank to send a table of every field.',
              )
            }}
          </p>
          <textarea
            id="fb-notif-body"
            class="fb-setting__input fb-setting__textarea"
            rows="8"
            :value="selected.body"
            @input="
              update({ body: ($event.target as HTMLTextAreaElement).value })
            "
          ></textarea>
        </div>

        <div v-if="tokens.length" class="fb-notifs__tokens">
          <span class="fb-notifs__tokens-label">{{
            t('Available tokens:')
          }}</span>
          <code
            v-for="token in tokens"
            :key="token.token"
            class="fb-notifs__token"
            :title="token.label"
          >
            {{ token.token }}
          </code>
        </div>

        <div class="fb-setting">
          <label class="fb-setting__label">{{ t('Attachments') }}</label>
          <div class="fb-setting__switch">
            <input
              id="fb-notif-attach"
              type="checkbox"
              :checked="selected.attachFiles"
              @change="
                update({
                  attachFiles: ($event.target as HTMLInputElement).checked,
                })
              "
            />
            <label for="fb-notif-attach">{{
              t('Attach the submission’s uploaded files')
            }}</label>
          </div>
        </div>
      </div>

      <div class="fb-settings__group">
        <h2 class="fb-settings__heading">{{ t('Conditions') }}</h2>
        <p class="fb-setting__instructions">
          {{
            t(
              'Only send this notification when the submission matches these rules.',
            )
          }}
        </p>
        <ConditionsEditor
          :model-value="selected.conditions"
          @update:model-value="updateConditions"
        />
      </div>

      <div class="fb-settings__group">
        <h2 class="fb-settings__heading">{{ t('Test') }}</h2>
        <div class="fb-notifs__test">
          <input
            v-model="testTo"
            class="fb-setting__input"
            type="text"
            :placeholder="t('you@example.com')"
          />
          <button
            type="button"
            class="btn"
            :disabled="testing"
            @click="sendTest"
          >
            {{ testing ? t('Sending…') : t('Send test') }}
          </button>
        </div>
        <p
          v-if="testMessage"
          class="fb-notifs__test-result"
          :class="{ 'fb-notifs__test-result--error': !testMessage.ok }"
        >
          {{ testMessage.text }}
        </p>
      </div>
    </section>

    <section v-else class="fb-notifs__editor fb-notifs__editor--empty">
      <p>{{ t('Select a notification to edit it, or add a new one.') }}</p>
    </section>
  </div>

  <div v-if="log.length" class="fb-notifs__log">
    <h2 class="fb-notifs__log-title">{{ t('Recent sends') }}</h2>
    <!-- The live region stays mounted so the message is announced when it
         appears, rather than arriving inside a node that was just inserted. -->
    <div role="status">
      <p
        v-if="resendMessage"
        class="fb-notifs__resend-result"
        :class="{ 'fb-notifs__resend-result--error': !resendMessage.ok }"
      >
        {{ resendMessage.text }}
      </p>
    </div>
    <table class="fb-notifs__log-table">
      <thead>
        <tr>
          <th>{{ t('Status') }}</th>
          <th>{{ t('Recipients') }}</th>
          <th>{{ t('Subject') }}</th>
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
              class="fb-notifs__log-status"
              :class="
                entry.success
                  ? 'fb-notifs__log-status--ok'
                  : 'fb-notifs__log-status--fail'
              "
            >
              {{ entry.success ? t('Sent') : t('Failed') }}
            </span>
          </td>
          <td>{{ entry.recipients }}</td>
          <td>
            {{ entry.subject
            }}<template v-if="entry.error"> - {{ entry.error }}</template>
          </td>
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
          <td class="fb-notifs__log-action">
            <button
              v-if="entry.canResend"
              type="button"
              class="btn small"
              :aria-label="
                t('Resend to {recipients}', { recipients: entry.recipients })
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
</template>

<style scoped>
.fb-notifs {
  display: grid;
  grid-template-columns: minmax(200px, 260px) 1fr;
  gap: 24px;
  align-items: start;
}

.fb-notifs__list-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 12px;
}

.fb-notifs__list-title {
  font-size: 14px;
  font-weight: 600;
  margin: 0;
}

.fb-notifs__empty {
  color: #606d7b;
  font-size: 13px;
  line-height: 1.5;
}

.fb-notifs__items {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.fb-notifs__item {
  display: flex;
  align-items: stretch;
  border: 1px solid #e3e5e8;
  border-radius: 5px;
  overflow: hidden;
}

.fb-notifs__item--active {
  border-color: #4a7cf6;
  box-shadow: 0 0 0 1px #4a7cf6;
}

.fb-notifs__item--error {
  border-color: #cf1124;
}

.fb-notifs__item-select {
  flex: 1;
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

.fb-notifs__item-name {
  font-size: 13px;
  font-weight: 500;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.fb-notifs__item-state {
  font-size: 11px;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: #3f9142;
}

.fb-notifs__item-state--off {
  color: #9aa5b1;
}

.fb-notifs__item-remove {
  border: none;
  border-left: 1px solid #e3e5e8;
  background: none;
  cursor: pointer;
  padding: 0 10px;
  font-size: 18px;
  line-height: 1;
  color: #9aa5b1;
}

.fb-notifs__item-remove:hover {
  color: #cf1124;
}

.fb-notifs__editor--empty {
  color: #606d7b;
  font-size: 13px;
  padding-top: 40px;
}

.fb-notifs__tokens {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin: -4px 0 12px;
}

.fb-notifs__tokens-label {
  font-size: 12px;
  color: #606d7b;
}

.fb-notifs__token {
  font-size: 12px;
  background: #f1f3f6;
  border-radius: 4px;
  padding: 2px 6px;
}

.fb-notifs__test {
  display: flex;
  gap: 8px;
  align-items: center;
}

.fb-notifs__test .fb-setting__input {
  flex: 1;
}

.fb-notifs__test-result {
  margin-top: 8px;
  font-size: 13px;
  color: #3f9142;
}

.fb-notifs__test-result--error {
  color: #cf1124;
}

.fb-notifs__log {
  margin-top: 28px;
  border-top: 1px solid #e3e5e8;
  padding-top: 16px;
}

.fb-notifs__log-title {
  font-size: 14px;
  font-weight: 600;
  margin: 0 0 12px;
}

.fb-notifs__log-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}

.fb-notifs__log-table th,
.fb-notifs__log-table td {
  text-align: left;
  padding: 6px 12px 6px 0;
  border-bottom: 1px solid #eef0f2;
  vertical-align: top;
}

.fb-notifs__log-table th {
  color: #606d7b;
  font-weight: 500;
}

.fb-notifs__log-status--ok {
  color: #3f9142;
}

.fb-notifs__log-status--fail {
  color: #cf1124;
}

.fb-notifs__log-table .fb-notifs__log-action {
  padding-right: 0;
  text-align: right;
  white-space: nowrap;
}

.fb-notifs__resend-result {
  margin: 0 0 12px;
  font-size: 13px;
  color: #3f9142;
}

.fb-notifs__resend-result--error {
  color: #cf1124;
}
</style>
