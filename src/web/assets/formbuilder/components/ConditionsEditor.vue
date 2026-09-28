<script setup lang="ts">
import { computed } from 'vue';
import { useBuilderStore } from '../stores/builder';
import {
  emptyConditionSet,
  type ConditionRule,
  type ConditionSet,
  type SelectOption,
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
  /**
   * Wording for the stored show/hide actions. A notification or an integration
   * passes its own pair - nothing is shown or hidden there, the set decides
   * whether the thing is sent - and leaving it out gives the show/hide pair a
   * field or page wants.
   */
  actions?: SelectOption[];
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

const actionOptions = computed<SelectOption[]>(
  () => props.actions ?? conditionsConfig.value?.actions ?? [],
);

/** Mirrors the option rows {@see OptionsControl} edits. */
interface FieldOptionConfig {
  label: string;
  value: string;
}

/**
 * The values the field a rule tests can actually hold, when it is an options
 * field. Typing one by hand is how an author ends up with a rule that silently
 * never matches, so the comparison value is picked from the same list the
 * submitter sees.
 */
function valueOptions(rule: ConditionRule): SelectOption[] | null {
  const field = store.allFields.find((f) => f.handle === rule.field);
  const options = field?.options;

  if (!Array.isArray(options)) {
    return null;
  }

  const resolved = (options as Partial<FieldOptionConfig>[])
    .map((option) => {
      // Mirrors FieldOption::getValue() - an option authored with a label
      // alone is stored under that label.
      const value = option.value || option.label || '';

      return { value, label: option.label || value };
    })
    .filter((option) => option.value !== '');

  if (rule.value === '') {
    return [{ value: '', label: t('Choose…') }, ...resolved];
  }

  // A rule written against an option that has since been renamed or removed
  // keeps its value rather than being silently rewritten to the first one.
  if (!resolved.some((option) => option.value === rule.value)) {
    return [...resolved, { value: rule.value, label: rule.value }];
  }

  return resolved;
}

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
              v-for="a in actionOptions"
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

            <template v-if="!operatorIsUnary(rule.operator)">
              <select
                v-if="valueOptions(rule)"
                class="fb-conditions__select fb-conditions__value"
                :value="rule.value"
                @change="
                  updateRule(index, {
                    value: ($event.target as HTMLSelectElement).value,
                  })
                "
              >
                <option
                  v-for="o in valueOptions(rule) ?? []"
                  :key="o.value === '' ? '__placeholder' : String(o.value)"
                  :value="o.value"
                >
                  {{ o.label }}
                </option>
              </select>

              <input
                v-else
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
            </template>

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
