<?php

declare(strict_types=1);

namespace bytesof\formable\models;

use Twig\Markup;

/**
 * The two halves of a hand-laid-out form: everything before the author's own
 * markup and everything after it.
 *
 * A plain value object rather than a `craft\base\Model` - there's no config or
 * validation machinery worth its `__get` magic here, and that magic would only
 * add surprises around `Markup`.
 *
 * @api
 */
final class FormShell
{
    public function __construct(
        public readonly Markup $open,
        public readonly Markup $close,
        public readonly string $formId,
        public readonly bool $accepting,
    ) {
    }
}
