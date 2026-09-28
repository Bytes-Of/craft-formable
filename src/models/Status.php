<?php

declare(strict_types=1);

namespace bytesof\formable\models;

use Craft;
use craft\base\Model;
use craft\enums\Color;
use craft\validators\HandleValidator;

/**
 * A submission status (e.g. New, Approved).
 *
 * The row in `formable_statuses` is the read model; the definition itself lives
 * in project config under `formable.statuses.<uid>` - see
 * {@see \bytesof\formable\services\Statuses}.
 *
 * @internal
 */
final class Status extends Model
{
    public ?int $id = null;
    public string $name = '';
    public string $handle = '';
    public string $color = 'blue';
    public int $sortOrder = 0;
    public bool $isDefault = false;
    public ?string $uid = null;

    /**
     * The colors a status can be given: Craft's own palette, so a status
     * indicator on the submissions index looks like every other one in the
     * control panel.
     *
     * @return array<int, string>
     */
    public static function colors(): array
    {
        return array_map(static fn(Color $color): string => $color->value, Color::cases());
    }

    public function __toString(): string
    {
        return $this->name;
    }

    /**
     * The status as it is stored in project config. The uid is the key it is
     * stored under, so it is not repeated in the value.
     *
     * @return array<string, mixed>
     */
    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'handle' => $this->handle,
            'color' => $this->color,
            'sortOrder' => $this->sortOrder,
            'isDefault' => $this->isDefault,
        ];
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['name', 'handle'], 'required'],
            [['name', 'handle'], 'string', 'max' => 255],
            [['handle'], HandleValidator::class],
            // Anything outside Craft's palette renders as no indicator at all,
            // so it is rejected here rather than silently losing the color.
            [['color'], 'in', 'range' => self::colors()],
            [['sortOrder'], 'integer', 'min' => 0],
            [['isDefault'], 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributeLabels(): array
    {
        return [
            'name' => Craft::t('formable', 'Name'),
            'handle' => Craft::t('formable', 'Handle'),
            'color' => Craft::t('formable', 'Color'),
        ];
    }
}
