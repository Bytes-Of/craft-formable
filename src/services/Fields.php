<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\base\CompositeFormField;
use bytesof\formable\base\FormField;
use bytesof\formable\base\OptionsFormField;
use bytesof\formable\fields\Address;
use bytesof\formable\fields\Agree;
use bytesof\formable\fields\Assets;
use bytesof\formable\fields\Categories;
use bytesof\formable\fields\Checkboxes;
use bytesof\formable\fields\DateTime;
use bytesof\formable\fields\Dropdown;
use bytesof\formable\fields\Email;
use bytesof\formable\fields\Entries;
use bytesof\formable\fields\FileUpload;
use bytesof\formable\fields\Heading;
use bytesof\formable\fields\Hidden;
use bytesof\formable\fields\Html;
use bytesof\formable\fields\MissingField;
use bytesof\formable\fields\MultiSelect;
use bytesof\formable\fields\Name;
use bytesof\formable\fields\Number;
use bytesof\formable\fields\Phone;
use bytesof\formable\fields\Radio;
use bytesof\formable\fields\Rating;
use bytesof\formable\fields\Section;
use bytesof\formable\fields\Signature;
use bytesof\formable\fields\Table;
use bytesof\formable\fields\Text;
use bytesof\formable\fields\Textarea;
use bytesof\formable\fields\Url;
use bytesof\formable\fields\Users;
use bytesof\formable\models\FieldMap;
use Craft;
use craft\errors\MissingComponentException;
use craft\events\RegisterComponentTypesEvent;
use craft\helpers\Cp;
use Throwable;
use yii\base\Component;

/**
 * Registry for Formable field types, and the factory that turns form layout
 * JSON back into field instances.
 *
 * @internal
 */
final class Fields extends Component
{
    /**
     * @event RegisterComponentTypesEvent Fired when registering field types.
     *
     * Third-party field types are added here:
     *
     * ```php
     * Event::on(
     *     Fields::class,
     *     Fields::EVENT_REGISTER_FIELD_TYPES,
     *     function(RegisterComponentTypesEvent $event) {
     *         $event->types[] = MyField::class;
     *     },
     * );
     * ```
     *
     * @api
     */
    public const EVENT_REGISTER_FIELD_TYPES = 'registerFieldTypes';

    private const LEGACY_TYPE_PREFIX = 'vivid\\formable\\';

    private const TYPE_PREFIX = 'bytesof\\formable\\';

    /** @var array<int, class-string<FormField>>|null */
    private ?array $_fieldTypes = null;

    /** @var array<string, FieldMap> Keyed by a hash of the layout the map was built from. */
    private array $_fieldMaps = [];

    /**
     * The field types bundled with the plugin.
     *
     * @return array<int, class-string<FormField>>
     */
    public function getDefaultFieldTypes(): array
    {
        return [
            // Standard
            Text::class,
            Textarea::class,
            Email::class,
            Number::class,
            Phone::class,
            Url::class,
            DateTime::class,
            Hidden::class,
            // Choice
            Dropdown::class,
            Radio::class,
            Checkboxes::class,
            MultiSelect::class,
            Rating::class,
            Agree::class,
            // Advanced
            FileUpload::class,
            Name::class,
            Address::class,
            Signature::class,
            Table::class,
            // Layout
            Heading::class,
            Html::class,
            Section::class,
            // Relations
            Entries::class,
            Categories::class,
            Users::class,
            Assets::class,
        ];
    }

    /**
     * All registered field types, including those added by other plugins.
     *
     * @return array<int, class-string<FormField>>
     */
    public function getAllFieldTypes(): array
    {
        if ($this->_fieldTypes === null) {
            $event = new RegisterComponentTypesEvent([
                'types' => $this->getDefaultFieldTypes(),
            ]);

            $this->trigger(self::EVENT_REGISTER_FIELD_TYPES, $event);

            /** @var array<int, class-string<FormField>> $types */
            $types = array_values(array_unique($event->types));
            $this->_fieldTypes = $types;
        }

        return $this->_fieldTypes;
    }

    public function typeExists(string $type): bool
    {
        return in_array($type, $this->getAllFieldTypes(), true);
    }

    /**
     * Field types grouped for the builder palette, each with its display name,
     * icon and settings schema so the UI needs no per-type knowledge.
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function getPaletteDefinition(): array
    {
        $palette = [];

        foreach ($this->getAllFieldTypes() as $type) {
            if (!$type::isSelectable()) {
                continue;
            }

            $field = $this->createField(['type' => $type]);

            $palette[$type::group()][] = [
                'type' => $type,
                'name' => $type::displayName(),
                'icon' => $this->iconSvg($type::icon()),
                'hasValue' => $type::hasValue(),
                'settingsSchema' => $field->getSettingsSchema(),
                'defaults' => $field->toLayoutArray(),
                // What the Translations tab has to offer for this type - see
                // FormField::translatableProperties()/applyTranslation(). The
                // non-scalar shapes (options, sub-fields, table columns) are
                // flags rather than a list: which fields/sub-fields/columns
                // actually exist is per-instance data the builder already has
                // on the field itself, not something the palette knows.
                'translatable' => [
                    'properties' => $type::translatableProperties(),
                    'options' => is_a($type, OptionsFormField::class, true),
                    'subFields' => is_a($type, CompositeFormField::class, true),
                    'columns' => is_a($type, Table::class, true),
                ],
            ];
        }

        return $palette;
    }

    /**
     * Resolves a field type's icon name to inline SVG markup so the builder can
     * render a crisp, consistently sized glyph rather than relying on Craft's
     * ligature icon font (which only covers some of the names).
     *
     * {@see Cp::iconSvg()} needs the web application (locale, view); off a web
     * request - e.g. a console command that happens to build the palette - it
     * would fatal, so we degrade to an empty string there since the palette is
     * only ever rendered in the control panel.
     */
    private function iconSvg(string $icon): string
    {
        try {
            return Cp::iconSvg($icon) ?: '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Instantiates a field from its layout JSON.
     *
     * An unresolvable type yields a {@see MissingField} rather than an
     * exception, so one broken field can't take down a whole form.
     *
     * @param array<string, mixed>|string $config
     */
    public function createField(array|string $config): FormField
    {
        if (is_string($config)) {
            $config = ['type' => $config];
        }

        $type = $config['type'] ?? null;

        if (is_string($type)) {
            $type = self::mapLegacyType($type);
        }

        try {
            if (!is_string($type) || !is_subclass_of($type, FormField::class)) {
                throw new MissingComponentException('Field type is missing or invalid.');
            }

            unset($config['type']);

            /** @var FormField $field */
            $field = new $type();

            return $this->configure($field, $config);
        } catch (Throwable $e) {
            Craft::warning(
                sprintf('Could not create Formable field of type “%s”: %s', is_string($type) ? $type : 'null', $e->getMessage()),
                __METHOD__,
            );

            unset($config['type']);

            $field = new MissingField();
            $field->expectedType = is_string($type) ? $type : '';
            $field->errorMessage = $e->getMessage();

            return $this->configure($field, $config);
        }
    }

    /**
     * Rewrites a field type left over from the pre-1.0 `vivid\formable`
     * namespace, which 1.0.0 renamed to `bytesof\formable` because the `vivid`
     * vendor on Packagist belongs to an unrelated publisher.
     *
     * A stored layout is migrated in place, so this exists for the one input
     * migrations can't reach: a form exported from a pre-release build and
     * imported afterwards. Without it every field in that file resolves to
     * {@see MissingField}. See internal/decisions/0107.
     */
    private static function mapLegacyType(string $type): string
    {
        if (!str_starts_with($type, self::LEGACY_TYPE_PREFIX)) {
            return $type;
        }

        return self::TYPE_PREFIX . substr($type, strlen(self::LEGACY_TYPE_PREFIX));
    }

    /**
     * Applies a layout config to a field, skipping anything the class doesn't
     * declare.
     *
     * Unknown keys are dropped rather than fatal: a stored layout outlives the
     * class that wrote it, so a setting removed in a plugin update - or an
     * extra key posted by a stale builder tab - must not take down the whole
     * form. Yii's constructor config would throw UnknownPropertyException.
     *
     * @param array<string, mixed> $config
     */
    private function configure(FormField $field, array $config): FormField
    {
        foreach ($config as $name => $value) {
            if (is_string($name) && $field->canSetProperty($name)) {
                $field->$name = $value;
            }
        }

        return $field;
    }

    /**
     * A layout's field instances, built once per layout per request.
     *
     * Everything that needs a form's fields goes through here, so one request
     * hydrates each layout exactly once however many consumers ask for it -
     * see {@see FieldMap} for why that matters.
     *
     * The cache is keyed by the layout's *content*, not by form ID, so a form
     * edited mid-request can't be served a stale map: different pages, different
     * key. The hash costs one serialize of the layout array, which is an order
     * of magnitude cheaper than rebuilding its field objects.
     *
     * @param array<int, mixed> $pages
     */
    public function getFieldMap(array $pages): FieldMap
    {
        $key = md5(serialize($pages));

        return $this->_fieldMaps[$key] ??= new FieldMap($this->preparePages($pages));
    }

    /**
     * Flattens a form's page layout into field instances, in render order.
     *
     * @param array<int, mixed> $pages
     * @return array<int, FormField>
     */
    public function getFieldsFromLayout(array $pages): array
    {
        return $this->getFieldMap($pages)->all();
    }

    /**
     * The value-collecting fields of a layout, keyed by handle, optionally
     * narrowed to one page.
     *
     * The narrowed form is what a page-at-a-time caller wants - the uploads
     * service scoping to the page being posted, validation covering the page a
     * submitter was actually shown. It lives here, with the rest of the layout
     * lookups, rather than on the conditions engine: flattening a page's fields
     * is not a conditional decision, and a caller that only needs a page's
     * fields should not have to reach through the engine to get them.
     *
     * @param array<int, mixed> $pages
     * @return array<string, FormField>
     */
    public function getValueFieldsFromLayout(array $pages, ?int $pageIndex = null): array
    {
        return $this->getFieldMap($pages)->forPage($pageIndex)->valueFields();
    }

    /**
     * Turns layout JSON into the page/row structure a {@see FieldMap} holds,
     * instantiating each field config as it goes.
     *
     * Pages keep their layout index as their key; rows that carry no field are
     * dropped, since a template has nothing to render for one.
     *
     * @param array<int, mixed> $pages
     * @return array<int, array{id: string, label: string, settings: array<string, mixed>, rows: array<int, array{id: string, fields: array<int, FormField>}>}>
     */
    private function preparePages(array $pages): array
    {
        $prepared = [];

        foreach ($pages as $pageIndex => $page) {
            if (!is_array($page) || !is_int($pageIndex)) {
                continue;
            }

            $rows = [];

            foreach (is_array($page['rows'] ?? null) ? $page['rows'] : [] as $row) {
                if (!is_array($row)) {
                    continue;
                }

                $fields = [];

                foreach (is_array($row['fields'] ?? null) ? $row['fields'] : [] as $fieldConfig) {
                    if (is_array($fieldConfig) || is_string($fieldConfig)) {
                        $fields[] = $this->createField($fieldConfig);
                    }
                }

                if ($fields !== []) {
                    $rows[] = [
                        'id' => $this->stringValue($row['id'] ?? null),
                        'fields' => $fields,
                    ];
                }
            }

            $prepared[$pageIndex] = [
                'id' => $this->stringValue($page['id'] ?? null),
                'label' => $this->stringValue($page['label'] ?? null),
                'settings' => is_array($page['settings'] ?? null) ? $page['settings'] : [],
                'rows' => $rows,
            ];
        }

        return $prepared;
    }

    /**
     * A layout scalar as a string. Anything that isn't stringable - an array
     * where an ID belongs, in a hand-edited or half-migrated layout - reads as
     * empty rather than throwing on the cast.
     */
    private function stringValue(mixed $value): string
    {
        return is_scalar($value) ? (string)$value : '';
    }
}
