import { defineStore } from 'pinia';
import { computed, ref, toRaw, watch } from 'vue';
import { post } from '../api';
import { camelize, t, uuid } from '../helpers';
import {
  emptyIntegrationConfig,
  emptyLayoutErrors,
  emptyNotification,
  emptySiteTranslationSet,
  type BuilderConfig,
  type ConditionSet,
  type FieldConfig,
  type FieldTranslation,
  type FormIntegrationConfig,
  type FormState,
  type IntegrationDefinition,
  type IntegrationLogEntry,
  type LayoutErrors,
  type Notification,
  type NotificationLogEntry,
  type Page,
  type PaletteEntry,
  type ResendResponse,
  type Row,
  type SaveResponse,
  type SiteTranslationSet,
  type SubmissionCountResponse,
  type TestNotificationResponse,
  type Translations,
} from '../types';

const DRAFT_PREFIX = 'formable:draft:';
const DRAFT_DEBOUNCE_MS = 800;

/** How long a burst of changes (typing, a drag) coalesces into one undo step. */
const HISTORY_DEBOUNCE_MS = 500;
const HISTORY_LIMIT = 50;

export type BuilderTab =
  'fields' | 'settings' | 'notifications' | 'integrations' | 'translations';
export type SaveStatus = 'idle' | 'saving' | 'saved' | 'error';

/** What a locally-autosaved draft holds. */
interface Draft {
  savedAt: number;
  schemaVersion: string;
  form: FormState;
}

export const useBuilderStore = defineStore('builder', () => {
  const config = ref<BuilderConfig | null>(null);
  const form = ref<FormState>({
    id: null,
    title: '',
    handle: '',
    enabled: true,
    defaultStatusId: null,
    pages: [],
    settings: {},
    notifications: [],
    translations: {},
  });

  const activePageIndex = ref(0);
  const selectedFieldId = ref<string | null>(null);
  const activeTab = ref<BuilderTab>('fields');

  const dirty = ref(false);
  const saveStatus = ref<SaveStatus>('idle');
  const saveMessage = ref('');
  const layoutErrors = ref<LayoutErrors>(emptyLayoutErrors());
  const formErrors = ref<Record<string, string[]>>({});
  const settingsErrors = ref<Record<string, string[]>>({});
  const notificationErrors = ref<Record<string, Record<string, string[]>>>({});
  const selectedNotificationIndex = ref<number | null>(null);
  const selectedIntegrationHandle = ref<string | null>(null);
  const pendingDraft = ref<Draft | null>(null);

  const undoStack = ref<FormState[]>([]);
  const redoStack = ref<FormState[]>([]);
  const canUndo = computed(() => undoStack.value.length > 0);
  const canRedo = computed(() => redoStack.value.length > 0);

  let draftTimer: number | undefined;
  // Suppresses the dirty flag while we're the ones mutating state (booting,
  // restoring a draft, absorbing a save response).
  let suppressDirty = true;

  /**
   * A deep, non-reactive snapshot of the form as it stands. `form.value` is a
   * reactive Proxy, and `structuredClone()` rejects a Proxy outright -
   * `toRaw()` unwraps it back to plain data first.
   */
  function cloneFormState(): FormState {
    return structuredClone(toRaw(form.value));
  }

  // The state a burst of edits diverged from, so the debounced commit below
  // can push what came *before* rather than another copy of what came after.
  let historyBase: FormState = cloneFormState();
  let historyTimer: number | undefined;
  // Set around undo()/redo() so restoring a snapshot doesn't get recorded as
  // a change of its own.
  let applyingHistory = false;

  const activePage = computed<Page | undefined>(
    () => form.value.pages[activePageIndex.value],
  );

  /** Whether the active edition is Pro - the single gate every panel reads. */
  const isPro = computed<boolean>(() => config.value?.isPro ?? false);

  /** Deep-link to the plugin settings' Editions section for upgrade prompts. */
  const upgradeUrl = computed<string>(() => config.value?.upgradeUrl ?? '');

  const allFields = computed<FieldConfig[]>(() =>
    form.value.pages.flatMap((page) => page.rows.flatMap((row) => row.fields)),
  );

  /** The active page's fields in reading order, for the canvas move controls. */
  const activePageFields = computed<FieldConfig[]>(() =>
    (activePage.value?.rows ?? []).flatMap((row) => row.fields),
  );

  const selectedField = computed<FieldConfig | null>(
    () =>
      allFields.value.find((field) => field.id === selectedFieldId.value) ??
      null,
  );

  const paletteEntry = (type: string): PaletteEntry | undefined =>
    Object.values(config.value?.palette ?? {})
      .flat()
      .find((entry) => entry.type === type);

  const selectedFieldSchema = computed(() =>
    selectedField.value
      ? (paletteEntry(selectedField.value.type)?.settingsSchema ?? [])
      : [],
  );

  /** Handles already taken, so the drawer can warn before the server does. */
  const usedHandles = computed(() => {
    const counts: Record<string, number> = {};

    allFields.value.forEach((field) => {
      if (field.handle) {
        counts[field.handle] = (counts[field.handle] ?? 0) + 1;
      }
    });

    return counts;
  });

  /**
   * False for a notify-only form. Read off the live settings blob with the same
   * default the server applies to an unset value, so a form that never touched
   * the switch counts as storing.
   */
  const storesSubmissions = computed<boolean>(
    () => form.value.settings.storeSubmissions !== false,
  );

  /**
   * Integrations switched on for this form that the plugin would actually run -
   * the same intersection `FormSettings::validateIntegrationsNeedStorage()`
   * checks, so the builder warns about exactly what the server would refuse. A
   * stale entry for a deleted integration isn't listed, and isn't counted.
   */
  const switchedOnIntegrations = computed<IntegrationDefinition[]>(() =>
    (config.value?.integrations ?? []).filter(
      (integration) => integrationConfig(integration.handle).enabled,
    ),
  );

  /**
   * IDs of fields that were already in the layout when the builder loaded -
   * `config.form`, not the live, edited `form`. A field newly added this
   * session should still auto-derive its handle from the label; a field
   * that's already saved must not, or a label typo fix silently renames it
   * out from under stored answers and conditions.
   */
  const savedFieldIds = computed<Set<string>>(
    () =>
      new Set(
        (config.value?.form.pages ?? []).flatMap((page) =>
          page.rows.flatMap((row) => row.fields.map((field) => field.id)),
        ),
      ),
  );

  const errorsForField = (fieldId: string): Record<string, string[]> =>
    layoutErrors.value.fields[fieldId] ?? {};

  const fieldHasErrors = (fieldId: string): boolean =>
    Object.keys(errorsForField(fieldId)).length > 0;

  /** Page indexes carrying at least one error, for the tab strip's markers. */
  const pagesWithErrors = computed<Set<number>>(() => {
    const flagged = new Set<number>();

    form.value.pages.forEach((page, index) => {
      if (layoutErrors.value.pages[page.id]) {
        flagged.add(index);
      }

      if (
        page.rows.some((row) =>
          row.fields.some((field) => fieldHasErrors(field.id)),
        )
      ) {
        flagged.add(index);
      }
    });

    return flagged;
  });

  function init(builderConfig: BuilderConfig): void {
    suppressDirty = true;
    config.value = builderConfig;
    form.value = structuredClone(builderConfig.form);

    // Notifications are delivered alongside the form config rather than nested
    // in it (they live in their own table), so they're merged in here.
    form.value.notifications = structuredClone(
      builderConfig.notifications ?? [],
    );

    if (form.value.pages.length === 0) {
      form.value.pages = [newPage(1)];
    }

    pendingDraft.value = readDraft();
    dirty.value = false;
    suppressDirty = false;

    resetHistory();
  }

  function newPage(number: number): Page {
    return {
      id: uuid(),
      label: t('Page {number}', { number }),
      settings: {},
      rows: [],
    };
  }

  function addPage(): void {
    form.value.pages.push(newPage(form.value.pages.length + 1));
    activePageIndex.value = form.value.pages.length - 1;
  }

  function deletePage(index: number): void {
    // A form always has at least one page; the server rejects an empty layout.
    if (form.value.pages.length <= 1) {
      return;
    }

    form.value.pages.splice(index, 1);
    activePageIndex.value = Math.min(
      activePageIndex.value,
      form.value.pages.length - 1,
    );
  }

  function movePage(from: number, to: number): void {
    if (to < 0 || to >= form.value.pages.length) {
      return;
    }

    const [page] = form.value.pages.splice(from, 1);
    form.value.pages.splice(to, 0, page);
    activePageIndex.value = to;
  }

  /** Builds a field from its palette defaults, with a fresh ID and handle. */
  function createField(type: string): FieldConfig | null {
    const entry = paletteEntry(type);

    if (!entry) {
      return null;
    }

    // entry.defaults is read off the reactive config, and structuredClone
    // rejects a Proxy outright - toRaw() first gets back a plain object.
    const field: FieldConfig = {
      ...structuredClone(toRaw(entry.defaults)),
      id: uuid(),
      type,
    };

    field.label = entry.name;

    if (entry.hasValue) {
      field.handle = uniqueHandle(camelize(entry.name));
    }

    return field;
  }

  /** Appends `-2`, `-3`… until the handle is free within this form. */
  function uniqueHandle(base: string, ignoreFieldId?: string): string {
    const taken = new Set(
      allFields.value
        .filter((f) => f.id !== ignoreFieldId)
        .map((f) => f.handle),
    );

    if (!taken.has(base)) {
      return base;
    }

    let suffix = 2;

    while (taken.has(`${base}${suffix}`)) {
      suffix++;
    }

    return `${base}${suffix}`;
  }

  function addField(
    type: string,
    pageIndex = activePageIndex.value,
    rowIndex?: number,
  ): void {
    const field = createField(type);
    const page = form.value.pages[pageIndex];

    if (!field || !page) {
      return;
    }

    if (rowIndex === undefined) {
      page.rows.push({ id: uuid(), fields: [field] });
    } else {
      page.rows[rowIndex].fields.push(field);
    }

    selectedFieldId.value = field.id;
  }

  function updateField(fieldId: string, changes: Partial<FieldConfig>): void {
    const field = allFields.value.find((f) => f.id === fieldId);

    if (!field) {
      return;
    }

    const oldHandle = field.handle;

    Object.assign(field, changes);

    if (typeof changes.handle === 'string' && changes.handle !== oldHandle) {
      renameHandle(oldHandle, changes.handle);
    }
  }

  /**
   * Rewrites every condition rule and integration mapping that points at
   * `oldHandle` to point at `newHandle` instead, so changing a field's handle
   * doesn't strand the conditions and mappings built against it. Conditions
   * are evaluated by handle, not field ID - see `ConditionRule::$field`.
   */
  function renameHandle(oldHandle: string, newHandle: string): void {
    if (!oldHandle || oldHandle === newHandle) {
      return;
    }

    const renameRules = (set: ConditionSet | undefined): void => {
      set?.rules.forEach((rule) => {
        if (rule.field === oldHandle) {
          rule.field = newHandle;
        }
      });
    };

    form.value.pages.forEach((page) => {
      renameRules(page.settings.conditions as ConditionSet | undefined);

      page.rows.forEach((row) => {
        row.fields.forEach((field) => renameRules(field.conditions));
      });
    });

    form.value.notifications.forEach((notification) =>
      renameRules(notification.conditions),
    );

    Object.values(integrationConfigs()).forEach((integration) => {
      renameRules(integration.conditions);

      Object.entries(integration.values).forEach(([target, source]) => {
        if (source === oldHandle) {
          integration.values[target] = newHandle;
        }
      });
    });
  }

  /**
   * How many submissions the saved form has stored right now, for the
   * builder to warn with before committing a rename of an already-saved
   * field's handle - {@see renameHandle} rewrites the conditions and
   * mappings that point at it, and the save queues the job that moves stored
   * answers, which isn't instant on a large form.
   *
   * A new, unsaved form has nothing stored yet, and a failed lookup fails
   * open rather than blocking the edit on a network hiccup.
   */
  async function getSubmissionCount(): Promise<number> {
    if (!config.value || form.value.id === null) {
      return 0;
    }

    try {
      const response = await post<SubmissionCountResponse>(
        config.value.actions.submissionCount,
        { formId: form.value.id },
      );

      return response.count;
    } catch {
      return 0;
    }
  }

  function duplicateField(fieldId: string): void {
    for (const page of form.value.pages) {
      for (const row of page.rows) {
        const index = row.fields.findIndex((field) => field.id === fieldId);

        if (index === -1) {
          continue;
        }

        // row.fields[index] is reactive, and structuredClone rejects a Proxy
        // outright - toRaw() first gets back a plain object.
        const copy: FieldConfig = {
          ...structuredClone(toRaw(row.fields[index])),
          id: uuid(),
        };

        if (copy.handle) {
          copy.handle = uniqueHandle(copy.handle);
        }

        row.fields.splice(index + 1, 0, copy);
        selectedFieldId.value = copy.id;

        return;
      }
    }
  }

  function deleteField(fieldId: string): void {
    for (const page of form.value.pages) {
      for (const row of page.rows) {
        const index = row.fields.findIndex((field) => field.id === fieldId);

        if (index === -1) {
          continue;
        }

        row.fields.splice(index, 1);

        if (selectedFieldId.value === fieldId) {
          selectedFieldId.value = null;
        }

        // Drop the row too if that was its last field, so the canvas doesn't
        // keep an empty band that only shows up as a drop target.
        if (row.fields.length === 0) {
          page.rows.splice(page.rows.indexOf(row), 1);
        }

        return;
      }
    }
  }

  /**
   * Reorders a field one step through the active page's reading order, the
   * keyboard-operable equivalent of dragging it. The 2D row layout collapses
   * to a single sequence, so "up" and "down" stay meaningful whether the
   * neighbour sits in the same row or the next one; the two fields swap slots,
   * so row composition and each field's width are left untouched.
   */
  function moveField(fieldId: string, direction: -1 | 1): void {
    const page = activePage.value;

    if (!page) {
      return;
    }

    const slots = page.rows.flatMap((row) =>
      row.fields.map((field, fieldIndex) => ({ row, field, fieldIndex })),
    );

    const from = slots.findIndex((slot) => slot.field.id === fieldId);
    const to = from + direction;

    if (from === -1 || to < 0 || to >= slots.length) {
      return;
    }

    const a = slots[from];
    const b = slots[to];

    a.row.fields[a.fieldIndex] = b.field;
    b.row.fields[b.fieldIndex] = a.field;

    selectedFieldId.value = fieldId;
  }

  /**
   * Moves a row within the active page, the keyboard-operable equivalent of
   * its drag grip.
   */
  function moveRow(index: number, direction: -1 | 1): void {
    const page = activePage.value;
    const to = index + direction;

    if (!page || to < 0 || to >= page.rows.length) {
      return;
    }

    const [row] = page.rows.splice(index, 1);
    page.rows.splice(to, 0, row);
  }

  /**
   * Merges a row into its predecessor, the keyboard-operable equivalent of
   * dragging every field out of one row and into another. Always appends the
   * whole next row onto the end of this one and removes the row it emptied -
   * a single, predictable shape rather than asking which field lands where.
   * No-op past the last row or over `maxFieldsPerRow`.
   */
  function mergeRowDown(index: number): void {
    const page = activePage.value;
    const next = page?.rows[index + 1];

    if (!page || !next) {
      return;
    }

    const current = page.rows[index];
    const maxPerRow = config.value?.maxFieldsPerRow ?? 4;

    if (current.fields.length + next.fields.length > maxPerRow) {
      return;
    }

    current.fields.push(...next.fields);
    page.rows.splice(index + 1, 1);
  }

  /**
   * Pulls a field out into a standalone row of its own, the keyboard-operable
   * equivalent of dragging it out to split a shared row apart. The new row
   * always lands immediately after the field's old row, so splitting never
   * reorders any other field - it only inserts a row break. No-op if the
   * field is already alone in its row.
   */
  function splitField(fieldId: string): void {
    const page = activePage.value;

    if (!page) {
      return;
    }

    for (let i = 0; i < page.rows.length; i++) {
      const row = page.rows[i];
      const index = row.fields.findIndex((field) => field.id === fieldId);

      if (index === -1) {
        continue;
      }

      if (row.fields.length === 1) {
        return;
      }

      // row.fields.splice() builds its returned (removed-element) array
      // outside the reactive proxy, so the field it hands back is still
      // reactive - and nesting it inside this new row object hides it from
      // the shallow toRaw() that page.rows.splice()'s own reactive setter
      // does on its arguments. Left reactive, it survives fine until the
      // next structuredClone() (cloneFormState(), undo/redo, save's
      // resetHistory()) rejects it as an un-cloneable Proxy - toRaw() it
      // explicitly instead.
      const [field] = row.fields.splice(index, 1);
      page.rows.splice(i + 1, 0, { id: uuid(), fields: [toRaw(field)] });
      selectedFieldId.value = fieldId;

      return;
    }
  }

  /** Removes rows emptied by a drag between rows. Called after every drag. */
  function pruneEmptyRows(): void {
    form.value.pages.forEach((page) => {
      page.rows = page.rows.filter((row) => row.fields.length > 0);
    });
  }

  function addRow(pageIndex: number, atIndex?: number): Row {
    const row: Row = { id: uuid(), fields: [] };
    const page = form.value.pages[pageIndex];

    page.rows.splice(atIndex ?? page.rows.length, 0, row);

    return row;
  }

  function applyErrors(errors: SaveResponse['errors']): void {
    formErrors.value = errors?.form ?? {};
    settingsErrors.value = errors?.settings ?? {};
    layoutErrors.value = errors?.layout ?? emptyLayoutErrors();
    notificationErrors.value = errors?.notifications ?? {};
  }

  function clearErrors(): void {
    formErrors.value = {};
    settingsErrors.value = {};
    layoutErrors.value = emptyLayoutErrors();
    notificationErrors.value = {};
  }

  async function save(): Promise<boolean> {
    if (!config.value || saveStatus.value === 'saving') {
      return false;
    }

    saveStatus.value = 'saving';
    saveMessage.value = '';

    try {
      const response = await post<SaveResponse>(config.value.actions.save, {
        formId: form.value.id,
        title: form.value.title,
        handle: form.value.handle,
        enabled: form.value.enabled,
        defaultStatusId: form.value.defaultStatusId,
        pages: form.value.pages,
        settings: form.value.settings,
        notifications: form.value.notifications,
        translations: form.value.translations,
      });

      if (!response.success) {
        applyErrors(response.errors);
        saveStatus.value = 'error';
        saveMessage.value = firstError() ?? t('Couldn’t save form.');

        return false;
      }

      clearErrors();
      suppressDirty = true;

      // Clear the draft before adopting the new ID: the storage key is derived
      // from it, so discarding afterwards would delete the wrong key and leave
      // the "new form" draft behind to resurface on the next create.
      discardDraft();

      const isFirstSave = form.value.id === null;
      form.value.id = response.id ?? form.value.id;

      dirty.value = false;
      saveStatus.value = 'saved';
      saveMessage.value = saveMessageFor(response);
      suppressDirty = false;

      // Undo history recorded before the form had an ID would replay a null
      // ID on undo, and a save from there would create a duplicate form
      // rather than update this one. Only the first save changes the ID, so
      // only it needs to cut history off at this point.
      if (isFirstSave) {
        resetHistory();
      }

      // A new form lives at a different URL - move there so a reload doesn't
      // land back on the "create" screen and duplicate the form.
      if (isFirstSave && response.redirect) {
        window.history.replaceState({}, '', response.redirect);
      }

      return true;
    } catch (error) {
      saveStatus.value = 'error';
      saveMessage.value =
        error instanceof Error ? error.message : t('Couldn’t save form.');

      return false;
    }
  }

  /**
   * The status-bar text after a successful save. A rename that left a
   * notification's `{token}` pointing at the old handle is the one thing the
   * server can't fix for the author, so it's named here rather than buried.
   */
  function saveMessageFor(response: SaveResponse): string {
    const stale = response.staleTokenNotifications ?? [];

    if (stale.length === 0) {
      return response.message ?? '';
    }

    return t(
      'Form saved. These notifications still use a renamed field’s old handle in a token: {names}.',
      { names: stale.join(', ') },
    );
  }

  /** The first error worth surfacing in the status bar. */
  function firstError(): string | null {
    if (layoutErrors.value.general.length > 0) {
      return layoutErrors.value.general[0];
    }

    const fromForm = Object.values(formErrors.value)[0]?.[0];

    if (fromForm) {
      return fromForm;
    }

    const fromSettings = Object.values(settingsErrors.value)[0]?.[0];

    if (fromSettings) {
      return fromSettings;
    }

    const fieldErrors = Object.values(layoutErrors.value.fields)[0];

    if (fieldErrors) {
      const first = Object.values(fieldErrors)[0]?.[0];

      if (first) {
        return first;
      }
    }

    const notificationError = Object.values(notificationErrors.value)[0];

    return notificationError
      ? (Object.values(notificationError)[0]?.[0] ?? null)
      : null;
  }

  function addNotification(): void {
    const defaults = config.value?.notificationDefaults ?? {
      fromName: '',
      fromEmail: '',
      testTo: '',
    };
    form.value.notifications.push(emptyNotification(defaults));
    selectedNotificationIndex.value = form.value.notifications.length - 1;
  }

  function updateNotification(
    index: number,
    changes: Partial<Notification>,
  ): void {
    const notification = form.value.notifications[index];

    if (notification) {
      Object.assign(notification, changes);
    }
  }

  function removeNotification(index: number): void {
    form.value.notifications.splice(index, 1);

    if (selectedNotificationIndex.value === index) {
      selectedNotificationIndex.value = null;
    } else if (
      selectedNotificationIndex.value !== null &&
      selectedNotificationIndex.value > index
    ) {
      selectedNotificationIndex.value -= 1;
    }
  }

  const notificationErrorsFor = (index: number): Record<string, string[]> =>
    notificationErrors.value[String(index)] ?? {};

  /**
   * Sends a test copy of a notification as it currently stands in the editor -
   * saved or not - through the server, which renders it against sample values.
   */
  async function testNotification(
    index: number,
    to: string,
  ): Promise<TestNotificationResponse> {
    const notification = form.value.notifications[index];

    if (!config.value || !notification) {
      return { success: false, message: t('Nothing to send.') };
    }

    if (form.value.id === null) {
      return {
        success: false,
        message: t('Save the form before sending a test.'),
      };
    }

    try {
      return await post<TestNotificationResponse>(
        config.value.actions.testNotification,
        {
          formId: form.value.id,
          notification,
          to,
        },
      );
    } catch (error) {
      return {
        success: false,
        message:
          error instanceof Error ? error.message : t('Couldn’t send the test.'),
      };
    }
  }

  /**
   * Sends a failed delivery from the log again, then swaps in the log the
   * server answers with. The server decides which rows can still be resent, so
   * the builder never has to work out which button a success should remove.
   */
  async function resendNotification(
    logId: number,
  ): Promise<ResendResponse<NotificationLogEntry>> {
    const response = await resend<NotificationLogEntry>(
      config.value?.actions.resendNotification,
      logId,
    );

    if (response.log && config.value) {
      config.value.notificationLog = response.log;
    }

    return response;
  }

  async function resendIntegration(
    logId: number,
  ): Promise<ResendResponse<IntegrationLogEntry>> {
    const response = await resend<IntegrationLogEntry>(
      config.value?.actions.resendIntegration,
      logId,
    );

    if (response.log && config.value) {
      config.value.integrationLog = response.log;
    }

    return response;
  }

  async function resend<Entry>(
    action: string | undefined,
    logId: number,
  ): Promise<ResendResponse<Entry>> {
    if (!action || form.value.id === null) {
      return { success: false, message: t('Nothing to send.') };
    }

    try {
      return await post<ResendResponse<Entry>>(action, {
        formId: form.value.id,
        logId,
      });
    } catch (error) {
      return {
        success: false,
        message:
          error instanceof Error ? error.message : t('Couldn’t resend it.'),
      };
    }
  }

  /** Every integration's per-form config, read out of the settings blob. */
  function integrationConfigs(): Record<string, FormIntegrationConfig> {
    const raw = form.value.settings.integrations;

    return raw && typeof raw === 'object'
      ? (raw as Record<string, FormIntegrationConfig>)
      : {};
  }

  /** One integration's config, defaulted so the panel never binds to undefined. */
  function integrationConfig(handle: string): FormIntegrationConfig {
    return integrationConfigs()[handle] ?? emptyIntegrationConfig();
  }

  /**
   * Merges changes into an integration's per-form config, writing the settings
   * blob back immutably so the dirty-state watcher fires.
   */
  function updateIntegration(
    handle: string,
    changes: Partial<FormIntegrationConfig>,
  ): void {
    const current = integrationConfigs();
    const existing = current[handle] ?? emptyIntegrationConfig();

    form.value.settings = {
      ...form.value.settings,
      integrations: { ...current, [handle]: { ...existing, ...changes } },
    };
  }

  /** Sets a single mapping target to a form field handle (or clears it). */
  function mapIntegrationField(
    handle: string,
    target: string,
    source: string,
  ): void {
    const config = integrationConfig(handle);
    const values = { ...config.values };

    if (source === '') {
      delete values[target];
    } else {
      values[target] = source;
    }

    updateIntegration(handle, { values });
  }

  /**
   * A raw (non-reactive) snapshot of the translations blob. `form.value` is a
   * reactive Proxy, and nesting one of its Proxy-wrapped properties inside a
   * freshly built plain object (rather than reading it back out through
   * `toRaw()` first) leaves that Proxy embedded in the replacement value -
   * invisible until `structuredClone()` (undo/redo's snapshot stack, `save()`)
   * throws on it. Every update below reads through this rather than
   * `form.value.translations` directly, for the same reason `duplicateField()`
   * and `createField()` unwrap with `toRaw()` before cloning.
   */
  function rawTranslations(): Translations {
    return toRaw(form.value.translations);
  }

  /** One site's overrides, defaulted so the panel never binds to undefined. */
  function siteTranslations(siteUid: string): SiteTranslationSet {
    return rawTranslations()[siteUid] ?? emptySiteTranslationSet();
  }

  /**
   * Merges changes into one site's per-field override, writing the
   * translations blob back immutably so the dirty-state watcher fires.
   */
  function updateFieldTranslation(
    siteUid: string,
    fieldId: string,
    changes: Partial<FieldTranslation>,
  ): void {
    const translations = rawTranslations();
    const current = translations[siteUid] ?? emptySiteTranslationSet();
    const existingField = current.fields[fieldId] ?? {};

    form.value.translations = {
      ...translations,
      [siteUid]: {
        ...current,
        fields: {
          ...current.fields,
          [fieldId]: { ...existingField, ...changes },
        },
      },
    };
  }

  /** Sets one option's override label within a field's overrides. */
  function updateOptionTranslation(
    siteUid: string,
    fieldId: string,
    optionValue: string,
    label: string,
  ): void {
    const existingField = siteTranslations(siteUid).fields[fieldId] ?? {};

    updateFieldTranslation(siteUid, fieldId, {
      options: { ...existingField.options, [optionValue]: label },
    });
  }

  /** Sets one sub-field's override label within a composite field's overrides. */
  function updateSubFieldTranslation(
    siteUid: string,
    fieldId: string,
    subFieldHandle: string,
    label: string,
  ): void {
    const existingField = siteTranslations(siteUid).fields[fieldId] ?? {};

    updateFieldTranslation(siteUid, fieldId, {
      subFields: { ...existingField.subFields, [subFieldHandle]: label },
    });
  }

  /** Sets one table column's override label within a field's overrides. */
  function updateColumnTranslation(
    siteUid: string,
    fieldId: string,
    columnHandle: string,
    label: string,
  ): void {
    const existingField = siteTranslations(siteUid).fields[fieldId] ?? {};

    updateFieldTranslation(siteUid, fieldId, {
      columns: { ...existingField.columns, [columnHandle]: label },
    });
  }

  /**
   * Merges a change into one site's translatable-settings overrides, writing
   * the translations blob back immutably so the dirty-state watcher fires.
   */
  function updateSettingTranslation(
    siteUid: string,
    key: string,
    value: string,
  ): void {
    const translations = rawTranslations();
    const current = translations[siteUid] ?? emptySiteTranslationSet();

    form.value.translations = {
      ...translations,
      [siteUid]: {
        ...current,
        settings: { ...current.settings, [key]: value },
      },
    };
  }

  /**
   * Sets one page's override label, writing the translations blob back
   * immutably so the dirty-state watcher fires.
   */
  function updatePageTranslation(
    siteUid: string,
    pageId: string,
    label: string,
  ): void {
    const translations = rawTranslations();
    const current = translations[siteUid] ?? emptySiteTranslationSet();

    form.value.translations = {
      ...translations,
      [siteUid]: {
        ...current,
        pages: { ...current.pages, [pageId]: label },
      },
    };
  }

  function draftKey(): string {
    return `${DRAFT_PREFIX}${form.value.id ?? 'new'}`;
  }

  /**
   * Autosave is deliberately local. Persisting half-built layouts server-side
   * would mean either a drafts table or writing invalid layouts to the form
   * row; localStorage recovers an interrupted session without either.
   */
  function writeDraft(): void {
    try {
      window.localStorage.setItem(
        draftKey(),
        JSON.stringify({
          savedAt: Date.now(),
          schemaVersion: config.value?.schemaVersion ?? '',
          form: form.value,
        } satisfies Draft),
      );
    } catch {
      // Private browsing or a full quota - autosave is a convenience, not a
      // guarantee, so a failure here shouldn't interrupt editing.
    }
  }

  function readDraft(): Draft | null {
    try {
      const raw = window.localStorage.getItem(draftKey());
      const draft = raw ? (JSON.parse(raw) as Draft) : null;

      // A draft written before a plugin update may hold the layout in a shape
      // the update's migration has since rewritten in the database. Posting it
      // back would save the old shape as if it were the new one, so it's
      // dropped rather than offered.
      if (draft && draft.schemaVersion !== config.value?.schemaVersion) {
        return null;
      }

      return draft;
    } catch {
      return null;
    }
  }

  function restoreDraft(): void {
    if (!pendingDraft.value) {
      return;
    }

    suppressDirty = true;
    form.value = pendingDraft.value.form;
    pendingDraft.value = null;
    activePageIndex.value = 0;
    selectedFieldId.value = null;
    suppressDirty = false;
    dirty.value = true;

    resetHistory();
  }

  /** Starts a clean undo/redo history at the form's current state. */
  function resetHistory(): void {
    window.clearTimeout(historyTimer);
    historyTimer = undefined;
    undoStack.value = [];
    redoStack.value = [];
    historyBase = cloneFormState();
  }

  /** Commits the coalesced burst of changes since the last commit as one undo step. */
  function commitHistory(): void {
    undoStack.value.push(historyBase);

    if (undoStack.value.length > HISTORY_LIMIT) {
      undoStack.value.shift();
    }

    historyBase = cloneFormState();
  }

  function scheduleHistoryCommit(): void {
    // A fresh edit invalidates redo the instant it happens, not once its
    // debounce fires - otherwise the control would read as available for up
    // to HISTORY_DEBOUNCE_MS after a keystroke that has already made it
    // meaningless to press.
    if (historyTimer === undefined) {
      redoStack.value = [];
    }

    window.clearTimeout(historyTimer);
    historyTimer = window.setTimeout(() => {
      historyTimer = undefined;
      commitHistory();
    }, HISTORY_DEBOUNCE_MS);
  }

  /**
   * Folds a still-debounced burst of edits into a proper history entry.
   * Without this, hitting undo mid-burst (before the debounce fires) would
   * skip straight past the in-progress edit to whatever was last committed,
   * silently discarding it rather than undoing it.
   */
  function flushHistoryCommit(): void {
    if (historyTimer === undefined) {
      return;
    }

    window.clearTimeout(historyTimer);
    historyTimer = undefined;
    commitHistory();
  }

  function applyHistoryState(state: FormState): void {
    // Guards the watcher below: this replaces the whole form with an already-
    // recorded snapshot, so it must mark the form dirty and refresh the draft
    // like any other change, but must not itself be scheduled as a new one.
    applyingHistory = true;
    form.value = state;
    applyingHistory = false;

    historyBase = structuredClone(state);
    activePageIndex.value = Math.min(
      activePageIndex.value,
      Math.max(0, form.value.pages.length - 1),
    );
  }

  function undo(): void {
    flushHistoryCommit();

    if (undoStack.value.length === 0) {
      return;
    }

    // undoStack is itself a reactive ref, so even a plain entry comes back
    // wrapped in a reactive Proxy on read - toRaw() before it's used as a
    // structuredClone() source or assigned into form.value.
    const previous = toRaw(undoStack.value.pop() as FormState);

    redoStack.value.push(cloneFormState());
    applyHistoryState(previous);
  }

  function redo(): void {
    flushHistoryCommit();

    if (redoStack.value.length === 0) {
      return;
    }

    const next = toRaw(redoStack.value.pop() as FormState);

    undoStack.value.push(cloneFormState());
    applyHistoryState(next);
  }

  function discardDraft(): void {
    pendingDraft.value = null;

    // Cancel any debounced write still in flight, or it would rewrite the
    // draft moments after we cleared it.
    window.clearTimeout(draftTimer);

    try {
      window.localStorage.removeItem(draftKey());
    } catch {
      // See writeDraft().
    }
  }

  watch(
    form,
    () => {
      if (suppressDirty) {
        return;
      }

      dirty.value = true;
      saveStatus.value = 'idle';

      window.clearTimeout(draftTimer);
      draftTimer = window.setTimeout(writeDraft, DRAFT_DEBOUNCE_MS);

      // undo()/redo() record history themselves - this change is already a
      // history entry being replayed, not a new one to schedule.
      if (applyingHistory) {
        return;
      }

      scheduleHistoryCommit();
    },
    // Synchronous so the suppressDirty brackets around booting, restoring and
    // saving actually hold. With the default 'pre' flush the callback runs on
    // the next tick, by which time the flag is back to false and the form is
    // marked dirty the moment it loads.
    { deep: true, flush: 'sync' },
  );

  return {
    config,
    form,
    activePageIndex,
    selectedFieldId,
    activeTab,
    dirty,
    saveStatus,
    saveMessage,
    layoutErrors,
    formErrors,
    settingsErrors,
    notificationErrors,
    selectedNotificationIndex,
    selectedIntegrationHandle,
    pendingDraft,
    canUndo,
    canRedo,
    activePage,
    isPro,
    upgradeUrl,
    allFields,
    selectedField,
    selectedFieldSchema,
    usedHandles,
    storesSubmissions,
    switchedOnIntegrations,
    savedFieldIds,
    pagesWithErrors,
    activePageFields,
    init,
    paletteEntry,
    addPage,
    deletePage,
    movePage,
    createField,
    addField,
    updateField,
    renameHandle,
    getSubmissionCount,
    duplicateField,
    deleteField,
    moveField,
    moveRow,
    mergeRowDown,
    splitField,
    pruneEmptyRows,
    addRow,
    uniqueHandle,
    errorsForField,
    fieldHasErrors,
    notificationErrorsFor,
    clearErrors,
    save,
    addNotification,
    updateNotification,
    removeNotification,
    testNotification,
    resendNotification,
    resendIntegration,
    integrationConfig,
    updateIntegration,
    mapIntegrationField,
    siteTranslations,
    updateFieldTranslation,
    updateOptionTranslation,
    updateSubFieldTranslation,
    updateColumnTranslation,
    updateSettingTranslation,
    updatePageTranslation,
    restoreDraft,
    discardDraft,
    undo,
    redo,
  };
});
