<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\FormField;
use Craft;

/**
 * Drawn signature, captured from a canvas as a PNG data URL.
 *
 * @internal
 */
final class Signature extends FormField
{
    /**
     * Data URLs larger than this are rejected outright - a legitimate
     * signature is a few tens of KB, so anything bigger is a malformed or
     * hostile payload rather than a drawing.
     */
    private const MAX_LENGTH = 500_000;

    public int $canvasWidth = 400;
    public int $canvasHeight = 200;
    public string $penColor = '#000000';
    public string $backgroundColor = '#ffffff';

    public static function displayName(): string
    {
        return Craft::t('formable', 'Signature');
    }

    public static function icon(): string
    {
        return 'signature';
    }

    public static function group(): string
    {
        return self::GROUP_ADVANCED;
    }

    /**
     * A drawn signature is a PNG data URL of up to {@see MAX_LENGTH} characters,
     * so a form carrying one can push its session-held progress past what a
     * Redis or Memcached session backend will store.
     */
    public function isBulky(): bool
    {
        return true;
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['canvasWidth'], 'integer', 'min' => 100, 'max' => 2000];
        $rules[] = [['canvasHeight'], 'integer', 'min' => 50, 'max' => 1000];
        $rules[] = [['penColor', 'backgroundColor'], 'match', 'pattern' => '/^#[0-9a-f]{6}$/i'];

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return [
            [
                'name' => 'canvasWidth',
                'type' => 'number',
                'label' => Craft::t('formable', 'Width'),
                'default' => 400,
                'group' => 'appearance',
            ],
            [
                'name' => 'canvasHeight',
                'type' => 'number',
                'label' => Craft::t('formable', 'Height'),
                'default' => 200,
                'group' => 'appearance',
            ],
            [
                'name' => 'penColor',
                'type' => 'color',
                'label' => Craft::t('formable', 'Pen Color'),
                'default' => '#000000',
                'group' => 'appearance',
            ],
            [
                'name' => 'backgroundColor',
                'type' => 'color',
                'label' => Craft::t('formable', 'Background Color'),
                'default' => '#ffffff',
                'group' => 'appearance',
            ],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function findValueErrors(mixed $value): array
    {
        if (!is_string($value) || !$this->isValidDataUrl($value)) {
            return [
                Craft::t('formable', '{label} is not a valid signature.', ['label' => $this->label]),
            ];
        }

        return [];
    }

    private function isValidDataUrl(string $value): bool
    {
        if (strlen($value) > self::MAX_LENGTH) {
            return false;
        }

        if (!preg_match('/^data:image\/png;base64,([a-z0-9+\/]+={0,2})$/i', $value, $matches)) {
            return false;
        }

        return base64_decode($matches[1], true) !== false;
    }

    public function normalizeValue(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        return trim($value);
    }

    public function valueToString(mixed $value): string
    {
        // The data URL itself is meaningless in a CSV or email subject.
        return $this->normalizeValue($value) !== null
            ? Craft::t('formable', 'Signed')
            : '';
    }
}
