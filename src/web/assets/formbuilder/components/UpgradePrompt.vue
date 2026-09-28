<script setup lang="ts">
import { computed } from 'vue';
import { t } from '../helpers';
import { useBuilderStore } from '../stores/builder';

/**
 * A consistent "this is a Pro feature" notice for any builder panel that
 * exposes a Pro-only control in Lite. Pass the feature name; supply richer
 * copy via the default slot when a panel needs it (e.g. integrations, which
 * stay configurable in Lite and activate on upgrade).
 */
defineProps<{ feature: string }>();

const store = useBuilderStore();
// Deep-links to the plugin settings' Editions section so every Lite prompt
// lands on the same upgrade surface.
const upgradeUrl = computed<string>(() => store.upgradeUrl);
</script>

<template>
  <div class="fb-upgrade">
    <span class="fb-upgrade__pill">{{ t('Pro') }}</span>
    <span class="fb-upgrade__text">
      <slot
        >{{ feature }} -
        {{ t('a Formable Pro feature. Upgrade to enable it.') }}</slot
      >
    </span>
    <a v-if="upgradeUrl" class="fb-upgrade__link" :href="upgradeUrl">{{
      t('Upgrade to Pro')
    }}</a>
  </div>
</template>

<style scoped>
.fb-upgrade {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  background: #fff8e6;
  border: 1px solid #f5d675;
  border-radius: 5px;
  padding: 10px 14px;
  font-size: 13px;
  margin-bottom: 16px;
}

.fb-upgrade__pill {
  display: inline-block;
  padding: 1px 7px;
  border-radius: 9px;
  background: #f3d072;
  color: #7a5b00;
  font-size: 11px;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.03em;
}

.fb-upgrade__link {
  margin-left: auto;
  font-weight: 600;
  white-space: nowrap;
}
</style>
