<script setup lang="ts">
import { computed } from 'vue';
import { useBuilderStore } from '../stores/builder';
import {
  emptyConditionSet,
  type ConditionRule,
  type ConditionSet,
} from '../types';
import { t } from '../helpers';
import UpgradePrompt from './UpgradePrompt.vue';

/**
 * Edits the conditional-logic rule set on a field or a page.
 *
 * The shape it emits is exactly what the PHP `ConditionSet` reads and what the
 * front-end engine evaluates - enable, an action (show/hide), a match mode
 * (all/any) and a list of {field, operator, value} rules - so what an author
 * builds here is what runs on the site with no translation in between.
 */
const props = defineProps<{
  modelValue: ConditionSet | undefined;
  /** The field this set belongs to, so it can't reference itself. */
  ownFieldId?: string;
}>();

const emit = defineEmits<{
  'update:modelValue': [value: ConditionSet];
}>();

const store = useBuilderStore();

const conditionsConfig = computed(() => store.config?.conditions);

/**
 * Conditional logic is a Pro feature. In Lite the editor is replaced by an
 * upgrade prompt everywhere it's mounted - page, field, notification and
 * integration conditions - and the server ignores any rules a downgrade left
 * behind, so an author never edits a control that does nothing.
 */
const isPro = computed(() => store.isPro);

const set = computed<ConditionSet>(
  () => props.modelValue ?? emptyConditionSet(),
);

/** The fields a rule can test - every value field on the form bar this one. */
const fieldOptions = computed(() =>
  store.allFields
    .filter((field) => field.handle && field.id !== props.ownFieldId)
    .map((field) => ({
      value: field.handle,
      label: field.label || field.handle,
    })),
);

function operatorIsUnary(operator: string): boolean {
  return (
    conditionsConfig.value?.operators.find((o) => o.value === operator)
      ?.unary ?? false
  );
}

function update(changes: Partial<ConditionSet>): void {
  emit('update:modelValue', { ...set.value, ...changes });
}

function updateRule(index: number, changes: Partial<ConditionRule>): void {
  const rules = set.value.rules.map((rule, i) =>
    i === index ? { ...rule, ...changes } : rule,
  );
  update({ rules });
}

function addRule(): void {
  const firstField = fieldOptions.value[0]?.value ?? '';
  const firstOperator = conditionsConfig.value?.operators[0]?.value ?? 'eq';

  update({
    rules: [
      ...set.value.rules,
      { field: firstField, operator: firstOperator, value: '' },
    ],
  });
}

function removeRule(index: number): void {
  update({ rules: set.value.rules.filter((_, i) => i !== index) });
}
</script>

<template>
  <div class="fb-conditions">
    <UpgradePrompt v-if="!isPro" :feature="t('Conditional logic')" />

    <template v-else>
      <label class="fb-conditions__toggle">
        <input
          type="checkbox"
          :checked="set.enabled"
          @change="
            update({ enabled: ($event.target as HTMLInputElement).checked })
          "
        />
        <span>{{ t('Enable conditional logic') }}</span>
      </label>

      <p v-if="!fieldOptions.length" class="fb-conditions__hint">
        {{
          t(
            'Add another field with a handle to this form to base a condition on it.',
          )
        }}
      </p>

      <template v-else-if="set.enabled">
        <div class="fb-conditions__sentence">
          <select
            class="fb-conditions__select"
            :value="set.action"
            @change="
              update({ action: ($event.target as HTMLSelectElement).value })
            "
          >
            <option
              v-for="a in conditionsConfig?.actions ?? []"
              :key="String(a.value)"
              :value="a.value"
            >
              {{ a.label }}
            </option>
          </select>
          <span>{{ t('this if') }}</span>
          <select
            class="fb-conditions__select"
            :value="set.match"
            @change="
              update({ match: ($event.target as HTMLSelectElement).value })
            "
          >
            <option
              v-for="m in conditionsConfig?.matches ?? []"
              :key="String(m.value)"
              :value="m.value"
            >
              {{ m.label }}
            </option>
          </select>
          <span>{{ t('of the following match:') }}</span>
        </div>

        <ul class="fb-conditions__rules">
          <li
            v-for="(rule, index) in set.rules"
            :key="index"
            class="fb-conditions__rule"
          >
            <select
              class="fb-conditions__select"
              :value="rule.field"
              @change="
                updateRule(index, {
                  field: ($event.target as HTMLSelectElement).value,
                })
              "
            >
              <option v-for="f in fieldOptions" :key="f.value" :value="f.value">
                {{ f.label }}
              </option>
            </select>

            <select
              class="fb-conditions__select"
              :value="rule.operator"
              @change="
                updateRule(index, {
                  operator: ($event.target as HTMLSelectElement).value,
                })
              "
            >
              <option
                v-for="o in conditionsConfig?.operators ?? []"
                :key="o.value"
                :value="o.value"
              >
                {{ o.label }}
              </option>
            </select>

            <input
              v-if="!operatorIsUnary(rule.operator)"
              type="text"
              class="fb-conditions__value"
              :value="rule.value"
              :placeholder="t('value')"
              @input="
                updateRule(index, {
                  value: ($event.target as HTMLInputElement).value,
                })
              "
            />

            <button
              type="button"
              class="fb-conditions__remove"
              :aria-label="t('Remove rule')"
              @click="removeRule(index)"
            >
              ×
            </button>
          </li>
        </ul>

        <button type="button" class="fb-conditions__add" @click="addRule">
          + {{ t('Add rule') }}
        </button>
      </template>
    </template>
  </div>
</template>
