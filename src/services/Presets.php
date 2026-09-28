<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\events\RegisterPresetsEvent;
use bytesof\formable\fields\Checkboxes;
use bytesof\formable\fields\Dropdown;
use bytesof\formable\fields\Email;
use bytesof\formable\fields\Name;
use bytesof\formable\fields\Phone;
use bytesof\formable\fields\Rating;
use bytesof\formable\fields\Textarea;
use bytesof\formable\models\Preset;
use bytesof\formable\Plugin;
use Craft;
use yii\base\Component;

/**
 * The registry of starter presets the new-form picker offers.
 *
 * Ships a blank baseline plus contact, registration and survey templates, and
 * lets third parties add their own through {@see EVENT_REGISTER_PRESETS} - the
 * same event-over-defaults shape the field registry uses. Each preset carries a
 * normalized page layout; seeding a new form is just handing those pages over.
 *
 * @internal
 */
final class Presets extends Component
{
    /**
     * @event RegisterPresetsEvent Fired when registering form presets.
     *
     * Third-party presets are added here:
     *
     * ```php
     * use bytesof\formable\models\Preset;
     *
     * Event::on(
     *     Presets::class,
     *     Presets::EVENT_REGISTER_PRESETS,
     *     function(RegisterPresetsEvent $event) {
     *         $event->presets[] = new Preset([
     *             'handle' => 'rsvp',
     *             'name' => 'RSVP',
     *             'description' => 'Name, email and a yes/no.',
     *             'pages' => $myNormalizedPages,
     *         ]);
     *     },
     * );
     * ```
     *
     * @api
     */
    public const EVENT_REGISTER_PRESETS = 'registerPresets';

    /**
     * The handle of the preset a new form falls back to when none is chosen -
     * the same name/email/message layout forms have always started from.
     */
    public const DEFAULT_HANDLE = 'contact';

    private ?Layout $_layout = null;

    /**
     * Overrides the layout service presets normalize their pages through, so the
     * registry can be unit tested without a booted plugin. Mirrors
     * {@see Layout::setFields()}.
     */
    public function setLayout(Layout $layout): void
    {
        $this->_layout = $layout;
    }

    public function getLayout(): Layout
    {
        return $this->_layout ??= Plugin::getInstance()->getLayout();
    }

    /**
     * Every preset, bundled and third-party, in picker order.
     *
     * Deduplicated by handle with the first occurrence winning, so a plugin
     * can't quietly replace a built-in out from under an author - an added
     * preset has to bring its own handle.
     *
     * @return array<int, Preset>
     */
    public function getAllPresets(): array
    {
        $event = new RegisterPresetsEvent([
            'presets' => $this->getDefaultPresets(),
        ]);

        $this->trigger(self::EVENT_REGISTER_PRESETS, $event);

        $byHandle = [];

        foreach ($event->presets as $preset) {
            if ($preset->handle !== '' && !isset($byHandle[$preset->handle])) {
                $byHandle[$preset->handle] = $preset;
            }
        }

        return array_values($byHandle);
    }

    /**
     * One preset by handle, or null if nothing claims it.
     */
    public function getPresetByHandle(string $handle): ?Preset
    {
        foreach ($this->getAllPresets() as $preset) {
            if ($preset->handle === $handle) {
                return $preset;
            }
        }

        return null;
    }

    /**
     * The presets bundled with the plugin.
     *
     * @return array<int, Preset>
     */
    public function getDefaultPresets(): array
    {
        return [
            $this->blankPreset(),
            $this->contactPreset(),
            $this->registrationPreset(),
            $this->surveyPreset(),
        ];
    }

    private function blankPreset(): Preset
    {
        return new Preset([
            'handle' => 'blank',
            'name' => Craft::t('formable', 'Blank'),
            'description' => Craft::t('formable', 'One empty page to build up from scratch.'),
            'pages' => $this->getLayout()->normalizePages([
                ['label' => Craft::t('formable', 'Page 1'), 'rows' => []],
            ]),
        ]);
    }

    private function contactPreset(): Preset
    {
        return new Preset([
            'handle' => self::DEFAULT_HANDLE,
            'name' => Craft::t('formable', 'Contact'),
            'description' => Craft::t('formable', 'Name, email and a message - the everyday contact form.'),
            // The long-standing default layout, so "contact" is exactly what a
            // new form used to start as.
            'pages' => $this->getLayout()->getDefaultPages(),
        ]);
    }

    private function registrationPreset(): Preset
    {
        return new Preset([
            'handle' => 'registration',
            'name' => Craft::t('formable', 'Registration'),
            'description' => Craft::t('formable', 'Name, email, phone and a ticket choice for sign-ups.'),
            'pages' => $this->getLayout()->normalizePages([
                [
                    'label' => Craft::t('formable', 'Page 1'),
                    'rows' => [
                        // Not `name` - Craft's HandleValidator reserves it.
                        ['fields' => [$this->field(Name::class, 'Full name', 'fullName', true)]],
                        ['fields' => [$this->field(Email::class, 'Email', 'email', true)]],
                        ['fields' => [$this->field(Phone::class, 'Phone', 'phone')]],
                        ['fields' => [$this->choiceField(Dropdown::class, 'Ticket type', 'ticketType', [
                            ['label' => Craft::t('formable', 'General admission'), 'value' => 'general'],
                            ['label' => Craft::t('formable', 'VIP'), 'value' => 'vip'],
                        ], true)]],
                    ],
                ],
            ]),
        ]);
    }

    private function surveyPreset(): Preset
    {
        return new Preset([
            'handle' => 'survey',
            'name' => Craft::t('formable', 'Survey'),
            'description' => Craft::t('formable', 'A rating, a topic picker and a comments box.'),
            'pages' => $this->getLayout()->normalizePages([
                [
                    'label' => Craft::t('formable', 'Page 1'),
                    'rows' => [
                        ['fields' => [$this->field(Rating::class, 'How would you rate us?', 'rating', true)]],
                        ['fields' => [$this->choiceField(Checkboxes::class, 'What did you use?', 'topics', [
                            ['label' => Craft::t('formable', 'Website'), 'value' => 'website'],
                            ['label' => Craft::t('formable', 'Support'), 'value' => 'support'],
                            ['label' => Craft::t('formable', 'Documentation'), 'value' => 'documentation'],
                        ])]],
                        ['fields' => [$this->field(Textarea::class, 'Anything else?', 'comments')]],
                    ],
                ],
            ]),
        ]);
    }

    /**
     * A plain field spec for a preset row.
     *
     * @param class-string<\bytesof\formable\base\FormField> $type
     * @return array<string, mixed>
     */
    private function field(string $type, string $label, string $handle, bool $required = false): array
    {
        return [
            'type' => $type,
            'label' => Craft::t('formable', $label),
            'handle' => $handle,
            'required' => $required,
        ];
    }

    /**
     * A choice field spec (dropdown, radio, checkboxes) with its options.
     *
     * @param class-string<\bytesof\formable\base\FormField> $type
     * @param array<int, array<string, string>> $options
     * @return array<string, mixed>
     */
    private function choiceField(string $type, string $label, string $handle, array $options, bool $required = false): array
    {
        return $this->field($type, $label, $handle, $required) + ['options' => $options];
    }
}
