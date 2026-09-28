<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted } from 'vue';
import { useBuilderStore, type BuilderTab } from '../stores/builder';
import { openPreview } from '../api';
import { t } from '../helpers';
import BuilderCanvas from './BuilderCanvas.vue';
import DraftBanner from './DraftBanner.vue';
import FieldPalette from './FieldPalette.vue';
import FormSettingsPanel from './FormSettingsPanel.vue';
import IntegrationsPanel from './IntegrationsPanel.vue';
import NotificationsPanel from './NotificationsPanel.vue';
import PageTabs from './PageTabs.vue';
import SettingsDrawer from './SettingsDrawer.vue';
import TranslationsPanel from './TranslationsPanel.vue';

const store = useBuilderStore();

const tabs: { id: BuilderTab; label: string }[] = [
  { id: 'fields', label: t('Fields') },
  { id: 'settings', label: t('Settings') },
  { id: 'notifications', label: t('Notifications') },
  { id: 'integrations', label: t('Integrations') },
  { id: 'translations', label: t('Translations') },
];

const titleError = computed(() => store.formErrors.title?.[0] ?? null);
const handleError = computed(() => store.formErrors.handle?.[0] ?? null);

const statusLabel = computed(() => {
  switch (store.saveStatus) {
    case 'saving':
      return t('Saving…');
    case 'saved':
      return t('Saved');
    case 'error':
      return store.saveMessage;
    default:
      return store.dirty ? t('Unsaved changes') : '';
  }
});

function warnOnUnload(event: BeforeUnloadEvent): void {
  if (!store.dirty) {
    return;
  }

  event.preventDefault();
  // Browsers ignore custom text now, but returnValue still has to be set for
  // the native prompt to appear at all.
  event.returnValue = t('You have unsaved changes.');
}

function preview(): void {
  if (!store.config) {
    return;
  }

  openPreview(store.config.actions.preview, {
    title: store.form.title,
    pages: store.form.pages,
    settings: store.form.settings,
    translations: store.form.translations,
  });
}

/** Whether focus sits in a control with its own native undo (a text field). */
function isEditingText(): boolean {
  const active = document.activeElement;

  return (
    active !== null &&
    (active.tagName === 'INPUT' ||
      active.tagName === 'TEXTAREA' ||
      (active as HTMLElement).isContentEditable)
  );
}

function onKeydown(event: KeyboardEvent): void {
  if (!(event.metaKey || event.ctrlKey)) {
    return;
  }

  if (event.key === 's') {
    event.preventDefault();
    void store.save();

    return;
  }

  // Leaves Cmd/Ctrl+Z alone inside a text field - the browser's own per-field
  // undo is what an author expects there, not a jump across the whole form.
  if (isEditingText()) {
    return;
  }

  if (event.key.toLowerCase() === 'z') {
    event.preventDefault();

    if (event.shiftKey) {
      store.redo();
    } else {
      store.undo();
    }
  } else if (event.key.toLowerCase() === 'y') {
    event.preventDefault();
    store.redo();
  }
}

onMounted(() => {
  window.addEventListener('beforeunload', warnOnUnload);
  window.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
  window.removeEventListener('beforeunload', warnOnUnload);
  window.removeEventListener('keydown', onKeydown);
});
</script>

<template>
  <div class="fb">
    <header class="fb__header">
      <div class="fb__identity">
        <label class="fb__sr-only" for="fb-title">{{
          t('Untitled form')
        }}</label>
        <input
          id="fb-title"
          v-model="store.form.title"
          class="fb__title"
          type="text"
          :placeholder="t('Untitled form')"
          :aria-invalid="titleError ? 'true' : undefined"
          :aria-describedby="titleError ? 'fb-title-error' : undefined"
        />
        <div class="fb__handle">
          <label class="fb__sr-only" for="fb-handle">{{ t('Handle') }}</label>
          <span class="fb__handle-prefix" aria-hidden="true">#</span>
          <input
            id="fb-handle"
            v-model="store.form.handle"
            class="fb__handle-input"
            type="text"
            spellcheck="false"
            :aria-invalid="handleError ? 'true' : undefined"
            :aria-describedby="handleError ? 'fb-handle-error' : undefined"
          />
        </div>
      </div>

      <div class="fb__actions">
        <span
          class="fb__status"
          :class="{ 'fb__status--error': store.saveStatus === 'error' }"
          role="status"
          aria-live="polite"
        >
          {{ statusLabel }}
        </span>
        <button
          type="button"
          class="btn small"
          :disabled="!store.canUndo"
          :aria-label="t('Undo')"
          :title="t('Undo (Cmd/Ctrl+Z)')"
          @click="store.undo()"
        >
          ↶
        </button>
        <button
          type="button"
          class="btn small"
          :disabled="!store.canRedo"
          :aria-label="t('Redo')"
          :title="t('Redo (Cmd/Ctrl+Shift+Z)')"
          @click="store.redo()"
        >
          ↷
        </button>
        <button type="button" class="btn" @click="preview()">
          {{ t('Preview') }}
        </button>
        <button
          type="button"
          class="btn submit"
          :disabled="store.saveStatus === 'saving'"
          @click="store.save()"
        >
          {{ store.saveStatus === 'saving' ? t('Saving…') : t('Save') }}
        </button>
      </div>
    </header>

    <p v-if="titleError" id="fb-title-error" class="fb__error">
      {{ titleError }}
    </p>
    <p v-if="handleError" id="fb-handle-error" class="fb__error">
      {{ handleError }}
    </p>

    <DraftBanner />

    <ul
      v-if="store.layoutErrors.general.length"
      class="fb__errors"
      role="alert"
    >
      <li v-for="error in store.layoutErrors.general" :key="error">
        {{ error }}
      </li>
    </ul>

    <nav class="fb__tabs" :aria-label="t('Form builder sections')">
      <button
        v-for="tab in tabs"
        :key="tab.id"
        type="button"
        class="fb__tab"
        :class="{ 'fb__tab--active': store.activeTab === tab.id }"
        :aria-current="store.activeTab === tab.id ? 'page' : undefined"
        @click="store.activeTab = tab.id"
      >
        {{ tab.label }}
      </button>
    </nav>

    <div v-if="store.activeTab === 'fields'" class="fb__workspace">
      <FieldPalette />

      <div class="fb__main">
        <PageTabs />
        <BuilderCanvas />
      </div>

      <SettingsDrawer />
    </div>

    <FormSettingsPanel v-else-if="store.activeTab === 'settings'" />

    <NotificationsPanel v-else-if="store.activeTab === 'notifications'" />

    <IntegrationsPanel v-else-if="store.activeTab === 'integrations'" />

    <TranslationsPanel v-else />
  </div>
</template>
