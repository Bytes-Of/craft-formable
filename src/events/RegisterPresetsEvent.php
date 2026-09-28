<?php

declare(strict_types=1);

namespace bytesof\formable\events;

use bytesof\formable\models\Preset;
use yii\base\Event;

/**
 * Fired so third parties can add their own starter presets to the new-form
 * picker - the extensibility surface for form templates, mirrored on the same
 * event pattern as the field registry's {@see \bytesof\formable\services\Fields::EVENT_REGISTER_FIELD_TYPES}.
 *
 * @api
 */
final class RegisterPresetsEvent extends Event
{
    /**
     * @var array<int, Preset>
     */
    public array $presets = [];
}
