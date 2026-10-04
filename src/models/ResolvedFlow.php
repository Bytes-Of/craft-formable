<?php

declare(strict_types=1);

namespace bytesof\formable\models;

/**
 * The flow id a render settled on, and whether it was minted for that render.
 *
 * A fresh id has no progress stored under it, so a statically cached page can
 * have it swapped for a per-visitor one. A pinned or request-carried id may
 * already own a stored incomplete submission, and replacing it would orphan
 * that row - so only a fresh one is marked replaceable.
 *
 * @internal
 */
final class ResolvedFlow
{
    public function __construct(
        public readonly string $id,
        public readonly bool $isFresh,
    ) {
    }
}
