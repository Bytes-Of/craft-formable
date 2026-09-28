import { ref, watch, type Ref } from 'vue';
import { uuid } from './helpers';

/**
 * Local, keyed mirror of a repeating settings list (options, table columns).
 *
 * These rows have no server-side identity - an option is just a label and a
 * value - but drag-and-drop and `v-for` need a key that survives editing. A
 * key derived from the content would change on every keystroke and cost the
 * input its focus, so rows get a client-only key here and it's stripped again
 * before the value goes back to the store: the PHP models reject unknown
 * properties.
 */
export interface Keyed {
  __key: string;
}

export function useKeyedRows<T extends object>(
  source: () => unknown,
  normalize: (value: unknown) => T[],
  emit: (rows: T[]) => void,
): {
  rows: Ref<(T & Keyed)[]>;
  commit: () => void;
  add: (row: T) => void;
  remove: (index: number) => void;
  replace: (rows: (T & Keyed)[]) => void;
} {
  const rows = ref([]) as Ref<(T & Keyed)[]>;

  const strip = (keyed: (T & Keyed)[]): T[] =>
    keyed.map(({ __key, ...rest }) => rest as unknown as T);

  function commit(): void {
    emit(strip(rows.value));
  }

  watch(
    source,
    (value) => {
      const incoming = normalize(value);

      // Re-key only when the incoming value actually differs from what we
      // hold; otherwise our own emit would bounce back and reset every key.
      if (JSON.stringify(incoming) === JSON.stringify(strip(rows.value))) {
        return;
      }

      rows.value = incoming.map((row) => ({ ...row, __key: uuid() }));
    },
    { immediate: true, deep: true },
  );

  return {
    rows,
    commit,
    add: (row: T) => {
      rows.value.push({ ...row, __key: uuid() });
      commit();
    },
    remove: (index: number) => {
      rows.value.splice(index, 1);
      commit();
    },
    replace: (next: (T & Keyed)[]) => {
      rows.value = next;
      commit();
    },
  };
}
