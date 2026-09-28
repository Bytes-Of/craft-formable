<?php

declare(strict_types=1);

namespace bytesof\formable\models;

use bytesof\formable\base\FormField;

/**
 * A form layout with every field config replaced by its instance - built once
 * per layout per request by {@see \bytesof\formable\services\Fields::getFieldMap()}.
 *
 * Field instances are hydrated value objects: nothing in the plugin mutates one
 * after it leaves the factory, so one set can be shared by everything that
 * needs it in a request - the renderer, the conditions engine, validation, the
 * notification tokens, an exporter. Before this existed, each of those built
 * its own set, and the ones that looped fields calling `getValue()` rebuilt the
 * whole layout per field: a 25-field form cost ~625 field constructions per
 * submission touched, per consumer.
 *
 * Pages keep their original index from the layout array. The conditions engine
 * reports visibility keyed by that index, so a map that quietly re-packed its
 * pages would hand back the wrong page for a malformed layout.
 *
 * @phpstan-type PreparedRow array{id: string, fields: array<int, FormField>}
 * @phpstan-type PreparedPage array{id: string, label: string, settings: array<string, mixed>, rows: array<int, PreparedRow>}
 *
 * @internal
 */
final class FieldMap
{
    /** @var array<int, FormField>|null */
    private ?array $_all = null;

    /** @var array<string, FormField>|null */
    private ?array $_valueFields = null;

    /** @var array<int, self> */
    private array $_pageMaps = [];

    /**
     * @param array<int, PreparedPage> $pages Keyed by the page's index in the layout.
     */
    public function __construct(
        private readonly array $pages,
    ) {
    }

    /**
     * The layout, page by page, with field instances in place of their configs.
     *
     * This is what the front-end templates render: they get objects rather than
     * raw JSON, so an override template never has to know the storage shape.
     *
     * @return array<int, PreparedPage>
     */
    public function getPages(): array
    {
        return $this->pages;
    }

    /**
     * Every field in the layout, in render order - layout fields included.
     *
     * @return array<int, FormField>
     */
    public function all(): array
    {
        if ($this->_all === null) {
            $fields = [];

            foreach ($this->pages as $page) {
                foreach ($page['rows'] as $row) {
                    foreach ($row['fields'] as $field) {
                        $fields[] = $field;
                    }
                }
            }

            $this->_all = $fields;
        }

        return $this->_all;
    }

    /**
     * The value-collecting fields, keyed by handle.
     *
     * @return array<string, FormField>
     */
    public function valueFields(): array
    {
        if ($this->_valueFields === null) {
            $fields = [];

            foreach ($this->all() as $field) {
                if ($field::hasValue() && $field->handle !== '') {
                    $fields[$field->handle] = $field;
                }
            }

            $this->_valueFields = $fields;
        }

        return $this->_valueFields;
    }

    public function byHandle(string $handle): ?FormField
    {
        return $this->valueFields()[$handle] ?? null;
    }

    /**
     * The map narrowed to a single page - or this map unchanged when no page is
     * named, so a caller with an optional page index needs no branch.
     *
     * A page index the layout doesn't have yields an empty map rather than an
     * error: a stale step index in a resumed session must not fatal.
     */
    public function forPage(?int $pageIndex): self
    {
        if ($pageIndex === null) {
            return $this;
        }

        return $this->_pageMaps[$pageIndex] ??= new self(
            isset($this->pages[$pageIndex]) ? [$pageIndex => $this->pages[$pageIndex]] : [],
        );
    }
}
