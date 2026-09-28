<?php

declare(strict_types=1);

namespace bytesof\formable\base;

use bytesof\formable\models\ConditionSet;
use bytesof\formable\Plugin;
use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use craft\validators\HandleValidator;

/**
 * Base class for all Formable field types.
 *
 * A field instance is a value object hydrated from the form layout JSON. It
 * knows how to describe its own settings (for the builder), validate and
 * normalize a submitted value, and name its render template.
 *
 * @api
 */
abstract class FormField extends Component implements FormFieldInterface
{
    public const GROUP_STANDARD = 'standard';
    public const GROUP_CHOICE = 'choice';
    public const GROUP_ADVANCED = 'advanced';
    public const GROUP_LAYOUT = 'layout';
    public const GROUP_RELATIONS = 'relations';

    /**
     * Default row width - the field shares its row equally with its siblings,
     * which is the 1.0 layout byte-for-byte.
     */
    public const WIDTH_AUTO = 'auto';

    /**
     * The body param sub-values post under, keyed by field handle.
     *
     * Deliberately outside `fields[…]`: a sub-input named
     * `fields[email][confirm]` would turn the field's own scalar value into an
     * array and destroy it.
     */
    public const SUB_VALUE_PARAM = 'formableSubValues';

    /**
     * Stable identifier for this field within its form layout. Generated on
     * creation so the builder can reorder fields and conditions can reference
     * a field that has been renamed.
     */
    public string $id = '';

    public string $label = '';
    public string $handle = '';
    public string $instructions = '';
    public bool $required = false;
    public string $cssClasses = '';

    /**
     * The `autocomplete` attribute value for the field's input, e.g.
     * `organization` or `street-address`. Empty means the browser gets no
     * hint. Field types with an obvious purpose (`Email`, `Url`) default this
     * to their matching token instead of leaving it blank.
     */
    public string $autocomplete = '';

    /**
     * Whether the field's label is kept for assistive technology but hidden
     * from sighted users.
     *
     * Emits `formable-visually-hidden` on the label or legend rather than
     * dropping the element, so the control keeps its accessible name. For
     * search-box and inline-label layouts where a visible label would only
     * repeat what the surrounding design already says.
     */
    public bool $labelHidden = false;

    /**
     * How much of its row the field occupies, when the optional theme's row
     * layout is in play.
     *
     * `auto` (the default) keeps every field in the row equal-width - the 1.0
     * behaviour unchanged. `full | half | third | quarter` size the field
     * explicitly; any `auto` siblings then share whatever width is left, so a
     * "First name / Last name / Suffix" row can give Suffix a quarter and let
     * the two names split the rest.
     *
     * Surfaces as `formable-field--width-*` on the field wrapper and
     * `data-formable-row-widths` on the row; a row of all-`auto` fields emits
     * neither and renders exactly as before.
     */
    public string $width = self::WIDTH_AUTO;

    /**
     * Whether this field's value is encrypted at rest.
     *
     * The GDPR "sensitive data" control: when on, the submission stores the
     * value ciphered with the site's security key and deciphers it only when a
     * value is actually read, so it never sits in the database in the clear and
     * never leaks into the element's title or search index.
     */
    public bool $encrypted = false;

    /**
     * Conditional-logic rule set, evaluated by the conditions engine. Opaque
     * here - fields only need to round-trip it.
     *
     * @var array<string, mixed>
     */
    public array $conditions = [];

    public static function icon(): string
    {
        return 'square';
    }

    public static function group(): string
    {
        return self::GROUP_STANDARD;
    }

    public static function hasValue(): bool
    {
        return true;
    }

    /**
     * Whether the field renders several inputs that share one label.
     *
     * Grouped fields are wrapped in a fieldset/legend instead of a `label`,
     * since a `for` attribute can only point at one control.
     */
    public function usesFieldset(): bool
    {
        return false;
    }

    /**
     * Whether this field is a boundary between groups of fields, rather than
     * a field in its own right.
     *
     * A row holding only a field that returns true here is never rendered as
     * a row - `_page.twig` consumes it to close the current group of rows and
     * open the next, wrapping each group in `.formable-group` once a page has
     * more than one. See {@see \bytesof\formable\fields\Section}.
     */
    public function startsGroup(): bool
    {
        return false;
    }

    /**
     * Whether this field can hold a value large enough to matter when a
     * multi-page form keeps its in-flight answers in the session between pages.
     *
     * A signature is a data URL of up to half a megabyte; a table has no
     * inherent row cap. A form carrying one of these has its progress payload
     * measured before each session write and is moved to the record-backed
     * store once it outgrows the session - see
     * {@see \bytesof\formable\services\Progress}. Every other field's value is
     * nowhere near that size, so the default is false and the measurement is
     * skipped entirely.
     */
    public function isBulky(): bool
    {
        return false;
    }

    /**
     * Whether the field emits its own label element.
     *
     * When true the wrapper skips its label, so a control never ends up with
     * two labels pointing at it - which screen readers concatenate and
     * accessibility checkers flag.
     */
    public function rendersOwnLabel(): bool
    {
        return false;
    }

    /**
     * Extra ids for `_field.twig` to fold into the control's
     * `aria-describedby`, beyond the instructions and error ids it already
     * builds - e.g. a hint a field template renders itself, like the file
     * count and size limits on {@see \bytesof\formable\fields\FileUpload}.
     *
     * A field template still owns the element these ids point to; this only
     * lists it so a screen reader announces it, without every field template
     * having to merge it into `describedBy` by hand.
     *
     * @return array<int, string>
     */
    public function extraDescribedBy(string $inputId): array
    {
        return [];
    }

    /**
     * Whether the author must give the field a label. False for fields whose
     * label is purely an internal name in the builder.
     */
    public static function requiresLabel(): bool
    {
        return true;
    }

    public function init(): void
    {
        parent::init();

        if ($this->id === '') {
            $this->id = StringHelper::UUID();
        }
    }

    public function __toString(): string
    {
        return $this->label;
    }

    /**
     * Validates the field's own definition, as authored in the builder.
     *
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        if (static::requiresLabel()) {
            $rules[] = [['label'], 'required'];
        }

        $rules[] = [['label', 'instructions', 'cssClasses', 'autocomplete'], 'string'];
        $rules[] = [['required', 'encrypted', 'labelHidden'], 'boolean'];
        $rules[] = [['width'], 'in', 'range' => self::widths()];

        if (static::hasValue()) {
            $rules[] = [['handle'], 'required'];
            $rules[] = [['handle'], 'string', 'max' => 64];
            $rules[] = [['handle'], HandleValidator::class, 'reservedWords' => self::reservedHandles()];
        }

        return $rules;
    }

    /**
     * Handles that would collide with submission attributes or template
     * variables once values are exposed to Twig and GraphQL.
     *
     * @return array<int, string>
     */
    public static function reservedHandles(): array
    {
        return [
            'id', 'uid', 'title', 'form', 'formId', 'submission', 'status',
            'statusId', 'dateCreated', 'dateUpdated', 'ipAddress', 'userAgent',
            'isIncomplete', 'isSpam', 'siteId',
        ];
    }

    /**
     * The row-width choices offered in the builder. `auto` is "no opinion -
     * share the row equally".
     *
     * @return array<int, string>
     */
    public static function widths(): array
    {
        return [self::WIDTH_AUTO, 'full', 'half', 'third', 'quarter'];
    }

    /**
     * @return array<string, string>
     */
    public function attributeLabels(): array
    {
        return [
            'label' => Craft::t('formable', 'Label'),
            'handle' => Craft::t('formable', 'Handle'),
            'instructions' => Craft::t('formable', 'Instructions'),
            'required' => Craft::t('formable', 'Required'),
            'encrypted' => Craft::t('formable', 'Encrypt Value'),
            'labelHidden' => Craft::t('formable', 'Hide Label'),
            'width' => Craft::t('formable', 'Width'),
            'cssClasses' => Craft::t('formable', 'CSS Classes'),
            'autocomplete' => Craft::t('formable', 'Autocomplete'),
        ];
    }

    /**
     * @inheritdoc
     */
    public function getSettingsSchema(): array
    {
        $schema = [
            [
                'name' => 'label',
                'type' => 'text',
                'label' => Craft::t('formable', 'Label'),
                'required' => true,
            ],
        ];

        if (static::hasValue()) {
            $schema[] = [
                'name' => 'handle',
                'type' => 'handle',
                'label' => Craft::t('formable', 'Handle'),
                'instructions' => Craft::t('formable', 'How this field is referenced in templates and exports.'),
                'required' => true,
                'sourceField' => 'label',
            ];
        }

        $schema[] = [
            'name' => 'instructions',
            'type' => 'textarea',
            'label' => Craft::t('formable', 'Instructions'),
        ];

        if (static::hasValue()) {
            $schema[] = [
                'name' => 'required',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Required'),
                'default' => false,
            ];
        }

        $schema = array_merge($schema, $this->defineSettingsSchema());

        if (static::hasValue()) {
            $schema[] = [
                'name' => 'encrypted',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Encrypt Value'),
                'instructions' => Craft::t('formable', 'Store this field’s value encrypted at rest, for sensitive data. It won’t be searchable or shown in the submission title.'),
                'default' => false,
                'group' => 'advanced',
            ];
        }

        if (static::hasValue() && !$this->rendersOwnLabel()) {
            $schema[] = [
                'name' => 'labelHidden',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Hide Label'),
                'instructions' => Craft::t('formable', 'Keep the label for screen readers but remove it visually, for search-box and inline-label layouts.'),
                'default' => false,
                'group' => 'appearance',
            ];
        }

        $schema[] = [
            'name' => 'width',
            'type' => 'select',
            'label' => Craft::t('formable', 'Width'),
            'instructions' => Craft::t('formable', 'How much of its row the field fills. Fields left on Auto share the row equally.'),
            'default' => self::WIDTH_AUTO,
            'group' => 'appearance',
            'options' => [
                ['value' => self::WIDTH_AUTO, 'label' => Craft::t('formable', 'Auto')],
                ['value' => 'full', 'label' => Craft::t('formable', 'Full width')],
                ['value' => 'half', 'label' => Craft::t('formable', 'Half')],
                ['value' => 'third', 'label' => Craft::t('formable', 'Third')],
                ['value' => 'quarter', 'label' => Craft::t('formable', 'Quarter')],
            ],
        ];

        $schema[] = [
            'name' => 'cssClasses',
            'type' => 'text',
            'label' => Craft::t('formable', 'CSS Classes'),
            'group' => 'appearance',
        ];

        return $schema;
    }

    /**
     * Field-type-specific settings, appended after the common ones.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return [];
    }

    /**
     * The field's conditional-logic rule set, or null when it has none
     * active.
     *
     * Handed to templates as the payload of the `data-formable-conditions`
     * attribute the front-end reads, so a field that no author ever gave a
     * condition emits no attribute at all.
     *
     * Null on Lite as well. The server ignores conditions there, so a client
     * still evaluating them would hide a field the server goes on requiring.
     *
     * @return array{enabled: bool, action: string, match: string, rules: array<int, array{field: string, operator: string, value: string}>}|null
     */
    public function getConditionsConfig(): ?array
    {
        if (!Plugin::getInstance()->getConditions()->isEnabled()) {
            return null;
        }

        $set = ConditionSet::fromArray($this->conditions);

        return $set->isActive() ? $set->toConfig() : null;
    }

    /**
     * Whether this field type opts into emitting a client-side validation
     * spec at all.
     *
     * Off by default: the declarative engine
     * ({@see \bytesof\formable\helpers\Validation}) only understands a single
     * control's raw string value, which fits a plain text/number input but
     * not a fieldset of radios or checkboxes, a boolean "must be checked"
     * consent box, or a field with no native `<input>` at all - those keep
     * validating server-side only, exactly as before this existed.
     */
    protected static function supportsClientValidation(): bool
    {
        return false;
    }

    /**
     * The field type's own contribution to its client-side validation spec -
     * `type`, `minLength`, `maxLength`, `min`, `max`. `required` is handled
     * once, generically, by {@see getValidationSpecConfig()} itself.
     *
     * @return array{type?: ?string, minLength?: ?int, maxLength?: ?int, min?: ?float, max?: ?float}
     */
    protected function defineValidationSpec(): array
    {
        return [];
    }

    /**
     * The field's declarative client-side validation spec, or null when
     * there is nothing for the front end to check - a field type that hasn't
     * opted in ({@see supportsClientValidation()}), or one that opted in but
     * is neither required nor carries any of the other constraints (an
     * optional Text field with no character limit, say).
     *
     * Handed to templates as the payload of the `data-formable-validation`
     * attribute the front-end reads - see
     * {@see \bytesof\formable\helpers\Validation} for what runs against it. The
     * spec is a preview only: {@see validateValue()} is still what actually
     * decides whether a submission is accepted.
     *
     * @return array{required: bool, type: ?string, minLength: ?int, maxLength: ?int, min: ?float, max: ?float, messages: array<string, string>}|null
     */
    public function getValidationSpecConfig(): ?array
    {
        if (!static::supportsClientValidation()) {
            return null;
        }

        $spec = array_merge([
            'type' => null,
            'minLength' => null,
            'maxLength' => null,
            'min' => null,
            'max' => null,
        ], $this->defineValidationSpec());
        $spec['required'] = $this->required;

        $hasConstraint = $spec['required']
            || $spec['type'] !== null
            || $spec['minLength'] !== null
            || $spec['maxLength'] !== null
            || $spec['min'] !== null
            || $spec['max'] !== null;

        if (!$hasConstraint) {
            return null;
        }

        $spec['messages'] = $this->buildValidationMessages($spec);

        return $spec;
    }

    /**
     * Localized, ready-to-show text for each constraint the spec actually
     * carries, keyed by the same rule code
     * {@see \bytesof\formable\helpers\Validation::evaluate()} returns - the
     * front end never assembles a message itself, it only looks one up.
     *
     * @param array{required: bool, type: ?string, minLength: ?int, maxLength: ?int, min: ?float, max: ?float} $spec
     * @return array<string, string>
     */
    private function buildValidationMessages(array $spec): array
    {
        $label = $this->label !== '' ? $this->label : $this->handle;
        $messages = [];

        if ($spec['required']) {
            $messages['required'] = Craft::t('formable', '{label} is required.', ['label' => $label]);
        }

        if ($spec['type'] !== null) {
            // The same fragments {@see \bytesof\formable\fields\Table} already
            // uses for its per-cell type checks, kept to one translated
            // phrase per type instead of a full sentence per field type.
            $fragment = match ($spec['type']) {
                'email' => Craft::t('formable', 'must be a valid email address'),
                'url' => Craft::t('formable', 'must be a valid URL'),
                'number' => Craft::t('formable', 'must be a number'),
                default => '',
            };

            if ($fragment !== '') {
                $messages['type'] = "$label $fragment.";
            }
        }

        if ($spec['minLength'] !== null) {
            $messages['minLength'] = Craft::t(
                'formable',
                '{label} must be at least {min, plural, one{# character} other{# characters}}.',
                ['label' => $label, 'min' => $spec['minLength']],
            );
        }

        if ($spec['maxLength'] !== null) {
            $messages['maxLength'] = Craft::t(
                'formable',
                '{label} must be at most {max, plural, one{# character} other{# characters}}.',
                ['label' => $label, 'max' => $spec['maxLength']],
            );
        }

        if ($spec['min'] !== null) {
            $messages['min'] = Craft::t('formable', '{label} must be at least {min}.', ['label' => $label, 'min' => $spec['min']]);
        }

        if ($spec['max'] !== null) {
            $messages['max'] = Craft::t('formable', '{label} must be at most {max}.', ['label' => $label, 'max' => $spec['max']]);
        }

        return $messages;
    }

    /**
     * @inheritdoc
     */
    public function getValueValidationRules(): array
    {
        $rules = [];

        if ($this->required && static::hasValue()) {
            $rules[] = ['required'];
        }

        return array_merge($rules, $this->defineValueValidationRules());
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineValueValidationRules(): array
    {
        return [];
    }

    /**
     * Checks that can't be expressed as a Yii rule - cross-cutting ones like
     * "at most N rows" or "this must parse as a date".
     *
     * Kept out of the rule set deliberately: Yii binds inline validators to
     * the model under validation, not to the field, so a closure here would
     * lose access to the field's own settings.
     *
     * @param mixed $value The already-normalized value
     * @return array<int, string> Error messages
     */
    protected function findValueErrors(mixed $value): array
    {
        return [];
    }

    /**
     * Checks against the field's sub-values - the inputs it renders that never
     * become part of its stored value, and so are gone by the time
     * {@see findValueErrors()} sees a normalized value.
     *
     * @param mixed $value The already-normalized value
     * @param array<string, mixed> $subValues Keyed as {@see getSubValueNames()}
     * @return array<int, string> Error messages
     */
    protected function findSubValueErrors(mixed $value, array $subValues): array
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function validateValue(mixed $value, array $subValues = []): array
    {
        if (!static::hasValue()) {
            return [];
        }

        $value = $this->normalizeValue($value);

        $model = new FieldValueModel(
            $value,
            $this->getValueValidationRules(),
            $this->label !== '' ? $this->label : $this->handle,
        );

        $model->validate();
        $errors = $model->getErrors('value');

        // Skip the custom checks when the value is absent - an optional empty
        // field shouldn't report "not a valid date" alongside nothing.
        if ($value !== null && $value !== '' && $value !== []) {
            $errors = array_merge($errors, $this->findValueErrors($value));
        }

        // Sub-value checks run even on an empty value: a submitter who filled
        // in only the confirmation box has still made a mistake worth naming.
        $errors = array_merge($errors, $this->findSubValueErrors($value, $subValues));

        return array_values($errors);
    }

    public function normalizeValue(mixed $value): mixed
    {
        return $value;
    }

    public function serializeValue(mixed $value): mixed
    {
        return $value;
    }

    public function valueToString(mixed $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? Craft::t('formable', 'Yes') : Craft::t('formable', 'No');
        }

        if (is_array($value)) {
            return implode(', ', array_map(
                static fn(mixed $item): string => is_scalar($item) ? (string)$item : '',
                $value,
            ));
        }

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @inheritdoc
     */
    public function getRelatedElementIds(mixed $value): array
    {
        return [];
    }

    public function getDefaultValue(): mixed
    {
        return null;
    }

    /**
     * @inheritdoc
     */
    public function getInputName(string $base = 'fields'): string
    {
        return "{$base}[{$this->handle}]";
    }

    /**
     * @inheritdoc
     */
    public function getSubValueNames(): array
    {
        return [];
    }

    /**
     * The `name` attribute for one of the field's sub-value inputs.
     */
    protected function subValueName(string $key): string
    {
        return self::SUB_VALUE_PARAM . "[{$this->handle}][{$key}]";
    }

    public function getInputTemplate(): string
    {
        $parts = explode('\\', static::class);

        return 'fields/' . lcfirst((string)array_pop($parts));
    }

    /**
     * The layout-array keys a translation override may replace for this field
     * type - both what the builder's Translations tab offers an input for and
     * what {@see applyTranslation()} actually reads.
     *
     * Kept as a class-level list, not derived from the instance, because the
     * builder palette needs the same answer for a field that doesn't exist
     * yet - see {@see \bytesof\formable\services\Fields::getPaletteDefinition()}.
     *
     * @return array<int, string>
     *
     * @internal
     */
    public static function translatableProperties(): array
    {
        return ['label', 'instructions'];
    }

    /**
     * Overlays `$override`'s translatable keys onto `$layout`, skipping any
     * override value that isn't a non-empty string - an author who clears an
     * override field means "no override", not "blank it out".
     *
     * Runs on the raw layout array, before {@see \bytesof\formable\services\Fields::createField()}
     * hydrates it, so the field object a template ends up with already carries
     * the translated strings as its own properties - see
     * {@see \bytesof\formable\services\Translations::localizePages()}.
     *
     * A field type with a non-scalar translatable key (options, sub-fields,
     * table columns) overrides this to handle its own shape, calling here
     * first for the scalar keys it shares with every other field.
     *
     * @param array<string, mixed> $layout
     * @param array<string, mixed> $override
     * @return array<string, mixed>
     *
     * @internal
     */
    public static function applyTranslation(array $layout, array $override): array
    {
        foreach (static::translatableProperties() as $key) {
            $value = $override[$key] ?? null;

            if (is_string($value) && $value !== '') {
                $layout[$key] = $value;
            }
        }

        return $layout;
    }

    /**
     * @inheritdoc
     */
    public function toLayoutArray(): array
    {
        $config = $this->toArray();
        $config['type'] = static::class;

        // Emit conditions in their canonical shape rather than the raw property
        // - so the builder palette, a stored layout and the front-end all read
        // the same `{ enabled, action, match, rules }` object, never a bare
        // `[]` the JS editor would choke on.
        $config['conditions'] = ConditionSet::fromArray($this->conditions)->toConfig();

        return $config;
    }
}
