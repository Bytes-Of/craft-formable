<script setup lang="ts">
import { computed } from 'vue';
import draggable from 'vuedraggable';
import { useBuilderStore } from '../stores/builder';
import { t } from '../helpers';
import type { PaletteEntry } from '../types';

const store = useBuilderStore();

const groups = computed(() =>
  Object.entries(store.config?.palette ?? {}).map(([group, entries]) => ({
    id: group,
    label: store.config?.groupLabels[group] ?? group,
    entries,
  })),
);

/**
 * The palette is a clone source: dragging out of it copies the entry rather
 * than removing it, and `onFieldClone` turns that copy into a real field with
 * its own ID and a free handle.
 */
function onFieldClone(entry: PaletteEntry) {
  return store.createField(entry.type) ?? entry.defaults;
}
</script>

<template>
  <aside class="fb-palette" :aria-label="t('Fields')">
    <div v-for="group in groups" :key="group.id" class="fb-palette__group">
      <h2 class="fb-palette__heading">{{ group.label }}</h2>

      <draggable
        :list="group.entries"
        :group="{ name: 'fields', pull: 'clone', put: false }"
        :sort="false"
        :clone="onFieldClone"
        item-key="type"
        class="fb-palette__list"
      >
        <template #item="{ element }">
          <button
            type="button"
            class="fb-palette__item"
            :title="element.name"
            @click="store.addField(element.type)"
          >
            <!-- eslint-disable vue/no-v-html -- trusted SVG from Craft's Cp::iconSvg() -->
            <span
              class="fb-palette__icon"
              aria-hidden="true"
              v-html="element.icon"
            ></span>
            <!-- eslint-enable vue/no-v-html -->
            <span class="fb-palette__label">{{ element.name }}</span>
          </button>
        </template>
      </draggable>
    </div>
  </aside>
</template>
