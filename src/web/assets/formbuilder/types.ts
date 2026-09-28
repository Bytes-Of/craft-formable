/**
 * The shapes exchanged with the server.
 *
 * These mirror the PHP side: `FieldConfig` is what `FormField::toLayoutArray()`
 * produces, `LayoutErrors` is what `services/Layout::validatePages()` returns.
 * Keep them in step.
 */

/** A field as stored in the layout. Type-specific settings live alongside the common keys. */
export interface FieldConfig {
  type: string;
  id: string;
  label: string;
  handle: string;
  instructions: string;
  required: boolean;
  cssClasses: string;
  labelHidden: boolean;
  /** Row width: `auto | full | half | third | quarter`. */
  width: string;
  conditions: ConditionSet;
  [setting: string]: unknown;
}

export interface Row {
  id: string;
  fields: FieldConfig[];
}

export interface Page {
  id: string;
  label: string;
  settings: Record<string, unknown>;
  rows: Row[];
}

export type ControlType =
  | 'text'
  | 'textarea'
  | 'handle'
  | 'number'
  | 'lightswitch'
  | 'select'
  | 'date'
  | 'datetime'
  | 'time'
  | 'color'
  | 'richText'
  | 'options'
  | 'checkboxGroup'
  | 'subFields'
  | 'tableColumns'
  | 'volumeSelect'
  | 'elementSources'
  | 'country';

export interface SelectOption {
  value: string | number | null;
  label: string;
}

/** One row of a settings drawer, as described by the field's PHP settings schema. */
export interface SettingSchema {
  name: string;
  type: ControlType;
  label: string;
  instructions?: string;
  required?: boolean;
  default?: unknown;
  group?: string;
  options?: SelectOption[];
  min?: number;
  max?: number;
  /** `handle` controls only: the setting to derive the handle from. */
  sourceField?: string;
  /** `options` controls only: whether several choices may be default. */
  multiple?: boolean;
  /** `subFields` controls only: the sub-field definitions to expose. */
  subFields?: Record<string, SubFieldDefinition>;
  /** `elementSources` controls only: which element type's sources to list. */
  elementType?: string;
  /**
   * Show this setting only while the listed settings hold the given
   * values. An array value means "one of" - any of the listed values
   * satisfies that entry, rather than requiring an exact match.
   */
  when?: Record<string, unknown | unknown[]>;
  /** A Pro-only setting: shown locked, behind an upgrade prompt, in Lite. */
  proOnly?: boolean;
}

export interface SubFieldDefinition {
  label?: string;
  enabled?: boolean;
  required?: boolean;
  type?: string;
  autocomplete?: string | null;
}

/**
 * What the Translations tab has to offer for a field type. Mirrors PHP's
 * `FormField::translatableProperties()` plus the non-scalar shapes
 * (`OptionsFormField`, `CompositeFormField`, `Table`) - see
 * `Fields::getPaletteDefinition()`.
 */
export interface TranslatableInfo {
  /** Scalar keys, e.g. `label`, `instructions`, `placeholder`. */
  properties: string[];
  /** Whether the field has an `options` list (option labels are translatable). */
  options: boolean;
  /** Whether the field has sub-fields (their labels are translatable). */
  subFields: boolean;
  /** Whether the field has table columns (their labels are translatable). */
  columns: boolean;
}

export interface PaletteEntry {
  type: string;
  name: string;
  /** Inline SVG markup for the palette glyph (resolved server-side). */
  icon: string;
  hasValue: boolean;
  settingsSchema: SettingSchema[];
  defaults: FieldConfig;
  translatable: TranslatableInfo;
}

export interface FormState {
  id: number | null;
  title: string;
  handle: string;
  enabled: boolean;
  defaultStatusId: number | null;
  pages: Page[];
  settings: Record<string, unknown>;
  notifications: Notification[];
  translations: Translations;
}

/**
 * A field's per-site overrides. Mirrors PHP's `FormField::applyTranslation()`
 * and its per-type overrides - every scalar key any field type actually
 * declares through `translatableProperties()` lives here, alongside the three
 * non-scalar shapes only some field types have.
 */
export interface FieldTranslation {
  label: string;
  instructions: string;
  placeholder: string;
  description: string;
  addRowLabel: string;
  selectionLabel: string;
  content: string;
  /** Option value → overridden label. */
  options: Record<string, string>;
  /** Sub-field handle → overridden label. */
  subFields: Record<string, string>;
  /** Table column handle → overridden label. */
  columns: Record<string, string>;
}

/** One site's full set of overrides, keyed by field ID (or, for `pages`, page ID). */
export interface SiteTranslationSet {
  fields: Record<string, Partial<FieldTranslation>>;
  /** Page ID → overridden label. */
  pages: Record<string, string>;
  /** One of Translations::TRANSLATABLE_SETTINGS → overridden value. */
  settings: Record<string, string>;
}

/** The `translations` blob, keyed by site UID. */
export type Translations = Record<string, SiteTranslationSet>;

export const emptySiteTranslationSet = (): SiteTranslationSet => ({
  fields: {},
  pages: {},
  settings: {},
});

/** Settings keys the Translations tab offers an override for. Mirrors PHP's Translations::TRANSLATABLE_SETTINGS. */
export const TRANSLATABLE_SETTINGS = [
  'successMessage',
  'successDetails',
  'errorMessage',
  'closedMessage',
  'submitButtonLabel',
  'nextButtonLabel',
  'backButtonLabel',
  'saveAndResumeLabel',
  'reviewPageLabel',
] as const;

/** An email notification. Mirrors PHP's Notification model. */
export interface Notification {
  id: number | null;
  name: string;
  enabled: boolean;
  recipients: string;
  cc: string;
  bcc: string;
  replyTo: string;
  fromName: string;
  fromEmail: string;
  subject: string;
  body: string;
  attachFiles: boolean;
  conditions: ConditionSet;
  sortOrder: number;
}

export interface EmailToken {
  token: string;
  label: string;
}

export interface NotificationDefaults {
  fromName: string;
  fromEmail: string;
  testTo: string;
}

export interface NotificationLogEntry {
  id: number;
  notificationId: number | null;
  submissionId: number | null;
  success: boolean;
  recipients: string;
  subject: string;
  error: string | null;
  /** Formatted server-side, in the viewing user's time zone. */
  date: string | null;
  submissionUrl: string | null;
  /** Whether the server will accept a resend of this row. */
  canResend: boolean;
}

export const emptyNotification = (
  defaults: NotificationDefaults,
): Notification => ({
  id: null,
  name: 'New notification',
  enabled: true,
  recipients: '',
  cc: '',
  bcc: '',
  replyTo: '',
  fromName: defaults.fromName,
  fromEmail: defaults.fromEmail,
  subject: '',
  body: '',
  attachFiles: false,
  conditions: emptyConditionSet(),
  sortOrder: 0,
});

export interface TestNotificationResponse {
  success: boolean;
  message: string;
}

/** How many submissions a saved form has stored, whatever state they're in. */
export interface SubmissionCountResponse {
  count: number;
}

/** A resend's outcome, with the refreshed delivery log it changed. */
export interface ResendResponse<Entry> {
  success: boolean;
  message: string;
  log?: Entry[];
}

/** A target field an integration maps form fields onto. Mirrors PHP's getMappableFields(). */
export interface IntegrationMappableField {
  handle: string;
  name: string;
  required?: boolean;
}

/** An enabled global integration a form can switch on. */
export interface IntegrationDefinition {
  handle: string;
  name: string;
  type: string;
  category: string;
  mappableFields: IntegrationMappableField[];
}

/** A form's per-integration config, stored under form.settings.integrations[handle]. */
export interface FormIntegrationConfig {
  enabled: boolean;
  /** Target field handle → the form field handle mapped onto it. */
  values: Record<string, string>;
  conditions: ConditionSet;
}

export interface IntegrationLogEntry {
  id: number;
  integration: string;
  submissionId: number | null;
  success: boolean;
  message: string | null;
  /** Formatted server-side, in the viewing user's time zone. */
  date: string | null;
  submissionUrl: string | null;
  /** Whether the server will accept a resend of this row. */
  canResend: boolean;
}

export const emptyIntegrationConfig = (): FormIntegrationConfig => ({
  enabled: false,
  values: {},
  conditions: emptyConditionSet(),
});

/** One clause of a condition set. Mirrors PHP's ConditionRule. */
export interface ConditionRule {
  field: string;
  operator: string;
  value: string;
}

/** A field or page's conditional-logic rule set. Mirrors PHP's ConditionSet. */
export interface ConditionSet {
  enabled: boolean;
  action: string;
  match: string;
  rules: ConditionRule[];
}

export interface ConditionOperator {
  value: string;
  label: string;
  /** Whether the operator ignores the comparison value (isEmpty / isNotEmpty). */
  unary: boolean;
}

export interface ConditionsConfig {
  operators: ConditionOperator[];
  actions: SelectOption[];
  matches: SelectOption[];
}

export const emptyConditionSet = (): ConditionSet => ({
  enabled: false,
  action: 'show',
  match: 'all',
  rules: [],
});

/** A site the Translations tab can pick overrides for. */
export interface SiteSummary {
  uid: string;
  name: string;
  handle: string;
}

export interface BuilderConfig {
  form: FormState;
  isNew: boolean;
  palette: Record<string, PaletteEntry[]>;
  groupLabels: Record<string, string>;
  formSettingsSchema: SettingSchema[];
  conditions: ConditionsConfig;
  statuses: SelectOption[];
  volumes: SelectOption[];
  elementSources: Record<string, SelectOption[]>;
  maxFieldsPerRow: number;
  /** The plugin's schema version, which changes whenever the stored layout's shape can. */
  schemaVersion: string;
  notifications: Notification[];
  emailTokens: EmailToken[];
  notificationDefaults: NotificationDefaults;
  notificationLog: NotificationLogEntry[];
  isPro: boolean;
  upgradeUrl: string;
  sites: SiteSummary[];
  integrations: IntegrationDefinition[];
  integrationLog: IntegrationLogEntry[];
  integrationsUrl: string;
  actions: {
    save: string;
    submissionCount: string;
    testNotification: string;
    resendNotification: string;
    resendIntegration: string;
    preview: string;
  };
  redirectUrl: string;
}

/** Errors keyed by page/field ID, so they survive reordering. */
export interface LayoutErrors {
  general: string[];
  pages: Record<string, Record<string, string[]>>;
  fields: Record<string, Record<string, string[]>>;
}

export interface SaveErrors {
  form: Record<string, string[]>;
  settings: Record<string, string[]>;
  layout: LayoutErrors;
  notifications: Record<string, Record<string, string[]>>;
}

export interface SaveResponse {
  success: boolean;
  id?: number;
  title?: string;
  handle?: string;
  redirect?: string | null;
  message?: string;
  renamedHandles?: Record<string, string>;
  staleTokenNotifications?: string[];
  errors?: SaveErrors;
}

export const emptyLayoutErrors = (): LayoutErrors => ({
  general: [],
  pages: {},
  fields: {},
});
