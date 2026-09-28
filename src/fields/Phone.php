<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\FormField;
use Craft;

/**
 * Telephone number input.
 *
 * @internal
 */
final class Phone extends FormField
{
    public string $placeholder = '';

    /**
     * Optional regex (without delimiters) the number must match. Left empty,
     * a permissive international check is used - phone formats vary too much
     * worldwide to reject aggressively by default.
     */
    public string $pattern = '';

    public static function displayName(): string
    {
        return Craft::t('formable', 'Phone Number');
    }

    public static function icon(): string
    {
        return 'phone';
    }

    /**
     * @return array<int, string>
     */
    public static function translatableProperties(): array
    {
        return array_merge(parent::translatableProperties(), ['placeholder']);
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['placeholder', 'pattern'], 'string'];
        $rules[] = [['pattern'], 'validatePattern'];

        return $rules;
    }

    public function validatePattern(string $attribute): void
    {
        if ($this->pattern === '') {
            return;
        }

        if (@preg_match('/' . $this->pattern . '/', '') === false) {
            $this->addError($attribute, Craft::t('formable', 'Enter a valid regular expression.'));
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return [
            [
                'name' => 'placeholder',
                'type' => 'text',
                'label' => Craft::t('formable', 'Placeholder'),
            ],
            [
                'name' => 'pattern',
                'type' => 'text',
                'label' => Craft::t('formable', 'Validation Pattern'),
                'instructions' => Craft::t('formable', 'An optional regular expression the number must match, without delimiters.'),
            ],
        ];
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineValueValidationRules(): array
    {
        $pattern = $this->pattern !== ''
            ? '/' . $this->pattern . '/'
            : '/^\+?[0-9\s().\-]{6,25}$/';

        return [
            ['string'],
            ['match', 'pattern' => $pattern, 'message' => Craft::t('formable', '{attribute} is not a valid phone number.')],
        ];
    }

    public function normalizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            return null;
        }

        return $value === null ? null : trim((string)$value);
    }

    /**
     * `required` only - no client-side format check. Phone formats vary too
     * much worldwide for even the default pattern above to be worth
     * approximating declaratively; see that property's own comment.
     */
    protected static function supportsClientValidation(): bool
    {
        return true;
    }
}
