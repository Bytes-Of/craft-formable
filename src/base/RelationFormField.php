<?php

declare(strict_types=1);

namespace bytesof\formable\base;

use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\services\ElementSources;

/**
 * Base for fields that relate the submission to existing Craft elements
 * (entries, categories).
 *
 * The stored value is a list of element IDs; titles are resolved lazily so a
 * submission never goes stale when the target element is renamed.
 *
 * @api
 */
abstract class RelationFormField extends FormField
{
    /**
     * The most elements a rendered picker offers, across every matched
     * source combined. A form is a public page, not an element index - there
     * is no paging control to fall back on, so the list is capped rather than
     * left to grow with the install.
     */
    public const MAX_SELECTABLE = 250;

    /**
     * Element sources the submitter may choose from. An empty list means all
     * sources of the element type.
     *
     * @var array<int, string>
     */
    public array $sources = [];

    public ?int $limit = null;

    public string $selectionLabel = '';

    /**
     * @var array<int, ElementInterface>|null
     */
    private ?array $_selectableElements = null;

    /**
     * @return class-string<ElementInterface>
     */
    abstract public static function elementType(): string;

    public static function group(): string
    {
        return self::GROUP_RELATIONS;
    }

    /**
     * Whether more than one element may be selected.
     */
    public static function isMultiple(): bool
    {
        return true;
    }

    /**
     * @return array<int, string>
     *
     * @internal
     */
    public static function translatableProperties(): array
    {
        return array_merge(parent::translatableProperties(), ['selectionLabel']);
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['limit'], 'integer', 'min' => 1];
        $rules[] = [['sources'], 'each', 'rule' => ['string']];

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return [
            [
                'name' => 'sources',
                'type' => 'elementSources',
                'label' => Craft::t('formable', 'Sources'),
                'instructions' => Craft::t('formable', 'Which sources the submitter can select from. Leave empty to allow all.'),
                'elementType' => static::elementType(),
            ],
            [
                'name' => 'limit',
                'type' => 'number',
                'label' => Craft::t('formable', 'Selection Limit'),
                'instructions' => Craft::t('formable', 'The maximum number of selections. Leave empty for no limit.'),
                'min' => 1,
            ],
            [
                'name' => 'selectionLabel',
                'type' => 'text',
                'label' => Craft::t('formable', 'Selection Label'),
                'instructions' => Craft::t('formable', 'The label shown on the selection button.'),
            ],
        ];
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineValueValidationRules(): array
    {
        return [
            ['each', 'rule' => ['integer', 'min' => 1]],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function findValueErrors(mixed $value): array
    {
        $errors = [];

        if ($this->limit !== null && is_array($value) && count($value) > $this->limit) {
            $errors[] = Craft::t('formable', '{label} can have at most {limit, plural, one{# selection} other{# selections}}.', [
                'label' => $this->label,
                'limit' => $this->limit,
            ]);
        }

        // No Craft app to check against in a unit test - see findElements().
        // A hand-rolled post can otherwise relate a submission to an id that
        // was never offered: disabled, unpublished, or outside every source
        // this field was configured with.
        if (Craft::$app !== null && Craft::$app->has('elements') && is_array($value) && $value !== []) {
            $selectableIds = array_map(
                static fn(ElementInterface $element): int => (int)$element->id,
                $this->getSelectableElements(),
            );

            if (array_diff($value, $selectableIds) !== []) {
                $errors[] = Craft::t('formable', '{label} contains a selection that is no longer available.', [
                    'label' => $this->label,
                ]);
            }
        }

        return $errors;
    }

    public function normalizeValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return [];
        }

        $ids = array_map(
            static fn(mixed $id): int => is_numeric($id) ? (int)$id : 0,
            is_array($value) ? array_values($value) : [$value],
        );

        return array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    }

    public function serializeValue(mixed $value): mixed
    {
        return $this->normalizeValue($value);
    }

    public function valueToString(mixed $value): string
    {
        $ids = $this->getRelatedElementIds($value);

        if ($ids === []) {
            return '';
        }

        $elements = $this->findElements($ids);

        if ($elements === null) {
            return implode(', ', $ids);
        }

        // Keyed by id and walked in selection order rather than rendering what
        // came back: a target that no longer exists has to be named as one,
        // not silently dropped. A submission that says nothing where an entry
        // used to be reads as "they picked nothing", which is a different
        // answer from the one that was given. It also collapses the several
        // rows a multi-site install returns for one element down to the one
        // selection the submitter actually made.
        $byId = [];

        foreach ($elements as $element) {
            $byId[(int)$element->id] = (string)$element;
        }

        return implode(', ', array_map(
            static fn(int $id): string => $byId[$id]
                ?? Craft::t('formable', 'Deleted element {id}', ['id' => $id]),
            $ids,
        ));
    }

    /**
     * @inheritdoc
     */
    public function getRelatedElementIds(mixed $value): array
    {
        // A relation field's normalized value already *is* its list of element
        // ids - positive integers, in selection order.
        $ids = $this->normalizeValue($value);

        /** @var array<int, int> */
        return is_array($ids) ? $ids : [];
    }

    /**
     * Resolves selected elements, or null when there is no Craft application
     * to query (unit tests, console bootstrapping).
     *
     * Trashed elements are included: a soft-deleted target can still be
     * restored, so a submission naming it is still accurate. Only a target
     * Craft has actually purged goes unresolved.
     *
     * @param array<int, int> $ids
     * @return array<int, ElementInterface>|null
     */
    protected function findElements(array $ids): ?array
    {
        if (Craft::$app === null || !Craft::$app->has('elements')) {
            return null;
        }

        $elementType = static::elementType();

        /** @var array<int, ElementInterface> $elements */
        $elements = $elementType::find()
            ->id($ids)
            ->fixedOrder()
            ->status(null)
            ->trashed(null)
            ->all();

        return $elements;
    }

    public function getDefaultValue(): mixed
    {
        return [];
    }

    public function getInputTemplate(): string
    {
        return 'fields/relation';
    }

    /**
     * Live elements the submitter may actually pick from - resolved from
     * {@see $sources} against Craft's own element-source criteria, capped at
     * {@see MAX_SELECTABLE}. Never every element of the type: a source scopes
     * to a section, a category group, or whatever criteria a custom source
     * carries, and Craft's own visibility rules (site, status) apply on top.
     *
     * Memoised on the instance the way `Table::$_columns` and
     * `OptionsFormField::$_options` are - private, written once, safe under
     * the one-field-map-per-request sharing model
     * ({@see \bytesof\formable\services\Fields::getFieldMap()}, decision 0018).
     *
     * @return array<int, ElementInterface>
     */
    public function getSelectableElements(): array
    {
        if ($this->_selectableElements !== null) {
            return $this->_selectableElements;
        }

        $elements = $this->queryElementsForSources($this->sources);

        if (count($elements) > self::MAX_SELECTABLE) {
            Craft::warning(
                sprintf(
                    'Formable: relation field "%s" has more than %d selectable elements; the rendered list was truncated.',
                    $this->handle,
                    self::MAX_SELECTABLE,
                ),
                'formable',
            );

            $elements = array_slice($elements, 0, self::MAX_SELECTABLE);
        }

        return $this->_selectableElements = $elements;
    }

    /**
     * Queries every live element matching the given source keys, uncapped and
     * unmemoised - {@see getSelectableElements()} does both on top of this.
     *
     * Isolated into its own method so a test can substitute canned elements
     * without a database; see `tests/Support/CannedSourceRelationField.php`.
     *
     * @param array<int, string> $sourceKeys
     * @return array<int, ElementInterface>
     */
    protected function queryElementsForSources(array $sourceKeys): array
    {
        if (Craft::$app === null || !Craft::$app->has('elements')) {
            return [];
        }

        $elementType = static::elementType();

        // CONTEXT_MODAL, not CONTEXT_INDEX: Entry and Category both gate their
        // CONTEXT_INDEX source list on "editable by the current user" (see
        // Entry::defineSources(), Categories::getEditableGroups()), which is
        // meaningless for an anonymous submitter - Entry throws outright with
        // no logged-in identity, and Category's list comes back empty. `modal`
        // is the context Craft's own relation fields query for exactly this
        // reason (BaseRelationField::inputHtml()); it returns every source,
        // same key format, with no such restriction.
        $available = Craft::$app->getElementSources()->getSources($elementType, ElementSources::CONTEXT_MODAL);

        $matched = $sourceKeys === []
            ? array_filter($available, static fn(array $source): bool => ($source['type'] ?? null) === ElementSources::TYPE_NATIVE)
            : array_filter($available, static fn(array $source): bool => in_array($source['key'] ?? null, $sourceKeys, true));

        // Not every element type has a "live" status (Category and User only
        // have enabled/disabled) - asking a query for a status it doesn't
        // define aborts it and silently returns nothing, which is exactly the
        // bug this method exists to fix. Use it only where it means something.
        $statuses = array_keys($elementType::statuses());
        $status = in_array('live', $statuses, true) ? 'live' : Element::STATUS_ENABLED;
        $siteId = Craft::$app->getSites()->getCurrentSite()->id;

        $elements = [];
        $seenIds = [];

        foreach ($matched as $source) {
            $query = $elementType::find();
            Craft::configure($query, $source['criteria'] ?? []);
            $query->status($status);
            $query->siteId($siteId);
            $query->orderBy('title');

            foreach ($query->all() as $element) {
                $id = (int)$element->id;

                if (!isset($seenIds[$id])) {
                    $seenIds[$id] = true;
                    $elements[] = $element;
                }
            }
        }

        return $elements;
    }
}
