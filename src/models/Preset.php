<?php

declare(strict_types=1);

namespace bytesof\formable\models;

use craft\base\Model;

/**
 * A starter layout offered when creating a form - the "blank" baseline and the
 * bundled contact / registration / survey templates, plus anything a third
 * party registers through {@see \bytesof\formable\services\Presets::EVENT_REGISTER_PRESETS}.
 *
 * A preset is just a name, a blurb and a normalized page layout: choosing one in
 * the new-form picker seeds the builder with its pages, nothing more. It's saved
 * like any other form from there on, so a preset never binds a form to anything.
 *
 * @api
 */
final class Preset extends Model
{
    /**
     * A stable, unique key - the value the picker posts to `forms/create` and
     * the handle a third party overrides or looks a preset up by.
     */
    public string $handle = '';

    public string $name = '';

    public string $description = '';

    /**
     * The starter layout, already normalized to the canonical
     * `pages[] → { id, label, settings, rows[] → { id, fields[] } }` shape, so
     * it can be handed straight to a new form.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $pages = [];
}
