<script setup lang="ts">
import { computed } from 'vue';
import { useBuilderStore } from '../stores/builder';
import { t } from '../helpers';

const store = useBuilderStore();

const savedAt = computed(() =>
  store.pendingDraft
    ? new Date(store.pendingDraft.savedAt).toLocaleString()
    : '',
);
</script>

<template>
  <div v-if="store.pendingDraft" class="fb-draft" role="alert">
    <p class="fb-draft__text">
      {{
        t('An unsaved version of this form was autosaved on {date}.', {
          date: savedAt,
        })
      }}
    </p>

    <div class="fb-draft__actions">
      <button type="button" class="btn" @click="store.restoreDraft()">
        {{ t('Restore draft') }}
      </button>
      <button type="button" class="btn" @click="store.discardDraft()">
        {{ t('Discard') }}
      </button>
    </div>
  </div>
</template>
