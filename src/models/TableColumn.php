<?php

declare(strict_types=1);

namespace bytesof\formable\models;

use Craft;
use craft\base\Model;
use craft\validators\HandleValidator;

/**
 * One column definition on a Table field.
 *
 * @internal
 */
final class TableColumn extends Model
{
    public const TYPE_TEXT = 'text';
    public const TYPE_NUMBER = 'number';
    public const TYPE_EMAIL = 'email';
    public const TYPE_URL = 'url';
    public const TYPE_SELECT = 'select';
    public const TYPE_CHECKBOX = 'checkbox';

    public string $handle = '';
    public string $label = '';
    public string $type = self::TYPE_TEXT;
    public string $width = '';

    /**
     * Choices for `select` columns.
     *
     * @var array<int, string>
     */
    public array $options = [];

    /**
     * @return array<int, string>
     */
    public static function types(): array
    {
        return [
            self::TYPE_TEXT,
            self::TYPE_NUMBER,
            self::TYPE_EMAIL,
            self::TYPE_URL,
            self::TYPE_SELECT,
            self::TYPE_CHECKBOX,
        ];
    }

    public function __toString(): string
    {
        return $this->label;
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        return [
            [['handle', 'label'], 'required'],
            [['handle'], HandleValidator::class],
            [['label', 'width'], 'string'],
            [['type'], 'in', 'range' => self::types()],
            [['options'], 'each', 'rule' => ['string']],
            [['options'], 'required', 'when' => fn(): bool => $this->type === self::TYPE_SELECT],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributeLabels(): array
    {
        return [
            'handle' => Craft::t('formable', 'Handle'),
            'label' => Craft::t('formable', 'Label'),
            'type' => Craft::t('formable', 'Type'),
        ];
    }
}
