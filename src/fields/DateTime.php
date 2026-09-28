<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\FormField;
use Craft;
use DateTimeImmutable;
use Exception;

/**
 * Date and/or time picker.
 *
 * Values are stored as ISO 8601 strings so a submission stays readable
 * independent of the site's locale or timezone settings at render time.
 *
 * @internal
 */
final class DateTime extends FormField
{
    public bool $includeDate = true;
    public bool $includeTime = false;

    /**
     * Bounds on the date part, as ISO 8601 date strings, e.g. "2026-01-01".
     */
    public ?string $minDate = null;
    public ?string $maxDate = null;

    /**
     * Bounds on the time part of a time-only field, as "HH:MM" strings.
     * A field that also collects a date is bounded by {@see $minDate} /
     * {@see $maxDate} instead.
     */
    public ?string $minTime = null;
    public ?string $maxTime = null;

    public static function displayName(): string
    {
        return Craft::t('formable', 'Date/Time');
    }

    public static function icon(): string
    {
        return 'calendar';
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['includeDate', 'includeTime'], 'boolean'];
        $rules[] = [['minDate', 'maxDate', 'minTime', 'maxTime'], 'validateBound'];
        $rules[] = [['includeDate'], 'validateIncludesSomething'];

        return $rules;
    }

    public function validateBound(string $attribute): void
    {
        $value = $this->$attribute;

        if ($value !== null && $value !== '' && $this->toDateTime($value) === null) {
            $this->addError($attribute, str_contains($attribute, 'Time')
                ? Craft::t('formable', 'Enter a valid time.')
                : Craft::t('formable', 'Enter a valid date.'));
        }
    }

    public function validateIncludesSomething(): void
    {
        if (!$this->includeDate && !$this->includeTime) {
            $this->addError('includeDate', Craft::t('formable', 'Include a date, a time, or both.'));
        }
    }

    /**
     * The HTML input type matching the enabled parts.
     */
    public function getInputType(): string
    {
        if ($this->includeDate && $this->includeTime) {
            return 'datetime-local';
        }

        return $this->includeTime ? 'time' : 'date';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return [
            [
                'name' => 'includeDate',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Include Date'),
                'default' => true,
            ],
            [
                'name' => 'includeTime',
                'type' => 'lightswitch',
                'label' => Craft::t('formable', 'Include Time'),
                'default' => false,
            ],
            [
                'name' => 'minDate',
                'type' => 'date',
                'label' => Craft::t('formable', 'Earliest Date'),
                'when' => ['includeDate' => true],
            ],
            [
                'name' => 'maxDate',
                'type' => 'date',
                'label' => Craft::t('formable', 'Latest Date'),
                'when' => ['includeDate' => true],
            ],
            [
                'name' => 'minTime',
                'type' => 'time',
                'label' => Craft::t('formable', 'Earliest Time'),
                'when' => ['includeDate' => false, 'includeTime' => true],
            ],
            [
                'name' => 'maxTime',
                'type' => 'time',
                'label' => Craft::t('formable', 'Latest Time'),
                'when' => ['includeDate' => false, 'includeTime' => true],
            ],
        ];
    }

    /**
     * The `min` attribute for the rendered control, shaped for its input type,
     * or null when no lower bound applies.
     */
    public function getInputMin(): ?string
    {
        return $this->boundForInput($this->minDate, $this->minTime, '00:00');
    }

    /**
     * The `max` attribute for the rendered control, shaped for its input type,
     * or null when no upper bound applies.
     */
    public function getInputMax(): ?string
    {
        return $this->boundForInput($this->maxDate, $this->maxTime, '23:59');
    }

    private function boundForInput(?string $date, ?string $time, string $timeFallback): ?string
    {
        // A cleared bound arrives from the builder as "" rather than null.
        $date = $date === '' ? null : $date;
        $time = $time === '' ? null : $time;

        if ($this->includeDate && $this->includeTime) {
            // `datetime-local` needs a full local wall-clock string; a lone
            // time bound has no date to anchor it, so it is dropped.
            return $date !== null ? $date . 'T' . ($time ?? $timeFallback) : null;
        }

        if ($this->includeTime) {
            return $time;
        }

        return $date;
    }

    /**
     * @return array<int, string>
     */
    protected function findValueErrors(mixed $value): array
    {
        $date = is_string($value) ? $this->toDateTime($value) : null;

        if ($date === null) {
            return [
                Craft::t('formable', '{label} is not a valid date.', ['label' => $this->label]),
            ];
        }

        $errors = [];
        $minInput = $this->getInputMin();
        $maxInput = $this->getInputMax();
        $min = $minInput !== null ? $this->toDateTime($minInput) : null;
        $max = $maxInput !== null ? $this->toDateTime($maxInput) : null;

        if ($min !== null && $date < $min) {
            $errors[] = $this->boundError(true, $min);
        }

        if ($max !== null && $date > $max) {
            $errors[] = $this->boundError(false, $max);
        }

        return $errors;
    }

    private function boundError(bool $isMin, DateTimeImmutable $bound): string
    {
        $type = $this->getInputType();
        $params = [
            'bound' => $bound->format(match ($type) {
                'time' => 'H:i',
                'datetime-local' => 'Y-m-d H:i',
                default => 'Y-m-d',
            }),
        ];

        if ($type === 'time') {
            return $isMin
                ? Craft::t('formable', 'Choose a time at or after {bound}.', $params)
                : Craft::t('formable', 'Choose a time at or before {bound}.', $params);
        }

        if ($type === 'datetime-local') {
            return $isMin
                ? Craft::t('formable', 'Choose a date and time on or after {bound}.', $params)
                : Craft::t('formable', 'Choose a date and time on or before {bound}.', $params);
        }

        return $isMin
            ? Craft::t('formable', 'Choose a date on or after {bound}.', $params)
            : Craft::t('formable', 'Choose a date on or before {bound}.', $params);
    }

    public function normalizeValue(mixed $value): mixed
    {
        if ($value === null || $value === '' || is_array($value)) {
            return null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $date = $this->toDateTime((string)$value);

        if ($date === null) {
            // Hand the raw input to the validator so it can report it.
            return (string)$value;
        }

        return $date->format($this->getStorageFormat());
    }

    public function valueToString(mixed $value): string
    {
        $normalized = $this->normalizeValue($value);

        return is_string($normalized) ? $normalized : '';
    }

    /**
     * The format values are stored in, narrowed to the enabled parts.
     */
    public function getStorageFormat(): string
    {
        if ($this->includeDate && $this->includeTime) {
            return 'Y-m-d\TH:i';
        }

        return $this->includeTime ? 'H:i' : 'Y-m-d';
    }

    private function toDateTime(string $value): ?DateTimeImmutable
    {
        try {
            // Time-only values need a fixed date, or "14:30" would resolve
            // against today and make bound comparisons drift.
            $prefix = !$this->includeDate && $this->includeTime ? '1970-01-01 ' : '';

            return new DateTimeImmutable($prefix . $value);
        } catch (Exception) {
            return null;
        }
    }
}
