<?php

declare(strict_types=1);

namespace bytesof\formable\events;

use yii\base\Event;

/**
 * Fired so third parties can add their own integration provider classes to the
 * registry - the extensibility surface for the integrations framework, mirrored
 * on Craft's own `RegisterComponentTypesEvent`.
 *
 * @api
 */
final class RegisterIntegrationTypesEvent extends Event
{
    /**
     * @var array<int, class-string<\bytesof\formable\base\Integration>>
     */
    public array $types = [];
}
