<?php

declare(strict_types=1);

namespace bytesof\formable\dev;

use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\fields\Address;
use bytesof\formable\fields\Agree;
use bytesof\formable\fields\Categories;
use bytesof\formable\fields\Checkboxes;
use bytesof\formable\fields\DateTime;
use bytesof\formable\fields\Dropdown;
use bytesof\formable\fields\Email;
use bytesof\formable\fields\Entries;
use bytesof\formable\fields\FileUpload;
use bytesof\formable\fields\Heading;
use bytesof\formable\fields\Hidden;
use bytesof\formable\fields\Html;
use bytesof\formable\fields\MultiSelect;
use bytesof\formable\fields\Name;
use bytesof\formable\fields\Number;
use bytesof\formable\fields\Phone;
use bytesof\formable\fields\Radio;
use bytesof\formable\fields\Rating;
use bytesof\formable\fields\Section;
use bytesof\formable\fields\Signature;
use bytesof\formable\fields\Table;
use bytesof\formable\fields\Text;
use bytesof\formable\fields\Textarea;
use bytesof\formable\fields\Url;
use bytesof\formable\models\FormSettings;

/**
 * Every scenario the preview gallery shows, and the every-field-type layout
 * {@see AccessibilityFixturesTest} builds its fixtures from
 * ([[0034-a-devmode-only-preview-gallery]]) - one catalog, so the two can never disagree
 * about what "every field type" means.
 *
 * Forms are built in memory and never saved: {@see \bytesof\formable\services\Fields::getFieldMap()}
 * is keyed by the layout's content, not by form id, so an unsaved `Form`
 * renders its pages and fields correctly and two unsaved forms can never
 * collide. That covers every scenario here except the ones that lean on
 * {@see Submission::getForm()} - `prefilled`, `error`, `progress` and
 * `review` all resolve a submission's fields or page sequence by looking its
 * form up from `formId`, which only a saved form has. The preview controller
 * ([[0034-a-devmode-only-preview-gallery]]) has to save each scenario's form inside a
 * transaction it rolls back before the response is sent, the same shape
 * `AccessibilityFixturesTest` already uses - nothing persists, but the
 * lookup resolves during the render.
 *
 * @internal
 */
final class PreviewScenarios
{
    public const GROUP_FIELD_TYPES = 'Field types';
    public const GROUP_FIELD_STATES = 'Field states';
    public const GROUP_LAYOUT = 'Layout';
    public const GROUP_PROGRESS = 'Progress';
    public const GROUP_MULTI_PAGE = 'Multi-page';
    public const GROUP_MESSAGES = 'Messages';
    public const GROUP_BUTTONS = 'Buttons';
    public const GROUP_THEME_LEVELS = 'Theme levels';
    public const GROUP_COLOR_SCHEME = 'Colour scheme';
    public const GROUP_PALETTE = 'Palette';
    public const GROUP_EMBED = 'Embed contexts';

    /**
     * One field of every type that renders a user-facing control, plus the
     * three cosmetic blocks. Byte-identical to
     * `AccessibilityFixturesTest::a11yEveryFieldType()` today - handles, order
     * and all - so the two can be pointed at each other without changing a
     * single committed fixture.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function everyFieldType(): array
    {
        return [
            ['type' => Text::class, 'handle' => 'shortText', 'label' => 'Short text', 'required' => true, 'instructions' => 'Your full legal name.'],
            ['type' => Textarea::class, 'handle' => 'longText', 'label' => 'Message'],
            ['type' => Email::class, 'handle' => 'emailAddress', 'label' => 'Email address', 'requireConfirmation' => true],
            ['type' => Url::class, 'handle' => 'website', 'label' => 'Website'],
            ['type' => Number::class, 'handle' => 'quantity', 'label' => 'Quantity'],
            ['type' => Phone::class, 'handle' => 'phoneNumber', 'label' => 'Phone number'],
            ['type' => Name::class, 'handle' => 'fullName', 'label' => 'Name'],
            ['type' => Address::class, 'handle' => 'postalAddress', 'label' => 'Postal address'],
            ['type' => DateTime::class, 'handle' => 'eventDate', 'label' => 'Event date', 'includeTime' => true],
            ['type' => Dropdown::class, 'handle' => 'country', 'label' => 'Country', 'placeholder' => 'Choose one', 'options' => self::options('Kenya', 'Norway', 'Peru')],
            ['type' => Radio::class, 'handle' => 'contactMethod', 'label' => 'Preferred contact method', 'options' => self::options('Email', 'Phone', 'Post')],
            ['type' => Checkboxes::class, 'handle' => 'interests', 'label' => 'Interests', 'options' => self::options('News', 'Events', 'Offers')],
            ['type' => MultiSelect::class, 'handle' => 'skills', 'label' => 'Skills', 'options' => self::options('Design', 'Research', 'Writing')],
            ['type' => Rating::class, 'handle' => 'satisfaction', 'label' => 'How satisfied were you?'],
            ['type' => Agree::class, 'handle' => 'consent', 'label' => 'Consent', 'description' => 'I agree to the privacy policy.', 'required' => true],
            ['type' => Hidden::class, 'handle' => 'referralCode', 'label' => 'Referral code', 'defaultValue' => 'ABC-123'],
            ['type' => FileUpload::class, 'handle' => 'attachment', 'label' => 'Attachment'],
            ['type' => Signature::class, 'handle' => 'signature', 'label' => 'Signature', 'required' => true],
            ['type' => Table::class, 'handle' => 'lineItems', 'label' => 'Line items', 'columns' => [
                ['handle' => 'item', 'label' => 'Item', 'type' => 'text'],
                ['handle' => 'qty', 'label' => 'Quantity', 'type' => 'number'],
                ['handle' => 'size', 'label' => 'Size', 'type' => 'select', 'options' => ['S', 'M', 'L']],
            ]],
            ['type' => Categories::class, 'handle' => 'topics', 'label' => 'Topics'],
            ['type' => Entries::class, 'handle' => 'relatedPages', 'label' => 'Related pages'],
            ['type' => Heading::class, 'label' => 'More about you', 'level' => 'h3'],
            ['type' => Html::class, 'content' => '<p>We use this information to <a href="https://example.com/privacy">process your request</a>.</p>'],
            ['type' => Section::class],
        ];
    }

    /**
     * Every scenario the gallery renders, grouped in display order.
     *
     * Each entry is `key` (unique, addressable from the frame URL), `label`,
     * `group`, the `form` to render and the `options` to pass to
     * {@see \bytesof\formable\services\Rendering::renderForm()}. The `disabled`
     * field-state scenario also carries `synthesizeDisabled`: no field config
     * produces that state on its own (it's what the client-side conditions
     * engine puts a hidden field into - see `conditions-dom.ts`), so the frame
     * template is the one that has to fake it for the screenshot.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function scenarios(): array
    {
        return [
            ...self::fieldTypeScenarios(),
            ...self::fieldStateScenarios(),
            ...self::layoutScenarios(),
            ...self::progressScenarios(),
            ...self::multiPageScenarios(),
            ...self::messageScenarios(),
            ...self::buttonScenarios(),
            ...self::themeLevelScenarios(),
            ...self::colorSchemeScenarios(),
            ...self::paletteScenarios(),
            ...self::embedScenarios(),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fieldTypeScenarios(): array
    {
        return array_map(
            static fn(array $field, int $index): array => self::scenario(
                'field-type-' . ($field['handle'] ?? self::shortName($field['type']) . '-' . $index),
                $field['label'] ?? self::shortName($field['type']),
                self::GROUP_FIELD_TYPES,
                self::form([[$field]]),
            ),
            self::everyFieldType(),
            array_keys(self::everyFieldType()),
        );
    }

    /**
     * `default` · `required` · `with instructions` · `prefilled` · `error` ·
     * `disabled`, over one text, one textarea, one select, one checkbox group
     * and one file upload - control shapes different enough that a state can
     * look right on one and wrong on another.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function fieldStateScenarios(): array
    {
        $fields = self::representativeFields();
        $handles = array_column($fields, 'handle');

        $withEach = static fn(array $extra): array => array_map(
            static fn(array $field): array => [...$field, ...$extra],
            $fields,
        );

        // Held as raw values, not a `Submission::setValue()`-populated
        // instance: `setValue()` resolves the field it's writing to through
        // `Submission::getForm()`, which needs a `formId` this catalog can't
        // supply - the form it belongs to is built alongside it, unsaved, and
        // only gets an id once `PreviewController::actionFrame()` saves it
        // inside its rollback transaction. The controller applies these once
        // that id exists.
        $prefillValues = [
            'shortText' => 'Jordan Alvarez',
            'longText' => "A couple of lines about what I'm looking for.",
            'country' => 'norway',
            'interests' => ['news', 'events'],
        ];

        $errored = new Submission();

        foreach ($handles as $handle) {
            $errored->addFieldError($handle, 'This field is required.');
        }

        return [
            self::scenario('field-state-default', 'Default', self::GROUP_FIELD_STATES, self::form([$fields])),
            self::scenario('field-state-required', 'Required', self::GROUP_FIELD_STATES, self::form([$withEach(['required' => true])])),
            self::scenario('field-state-instructions', 'With instructions', self::GROUP_FIELD_STATES, self::form([$withEach(['instructions' => 'A short explanation of what belongs here.'])])),
            self::scenario('field-state-prefilled', 'Prefilled', self::GROUP_FIELD_STATES, self::form([$fields]), ['prefillValues' => $prefillValues]),
            self::scenario('field-state-error', 'Error', self::GROUP_FIELD_STATES, self::form([$fields]), ['submission' => $errored]),
            [
                ...self::scenario('field-state-disabled', 'Disabled', self::GROUP_FIELD_STATES, self::form([$fields])),
                'synthesizeDisabled' => true,
            ],
        ];
    }

    /**
     * One-, two- and three-column rows, then the three cosmetic blocks on
     * their own row.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function layoutScenarios(): array
    {
        $column = static fn(string $handle, string $label): array => ['type' => Text::class, 'handle' => $handle, 'label' => $label];

        return [
            self::scenario('layout-columns-1', 'One-column row', self::GROUP_LAYOUT, self::buildForm([[
                [$column('columnA', 'Field A')],
            ]])),
            self::scenario('layout-columns-2', 'Two-column row', self::GROUP_LAYOUT, self::buildForm([[
                [$column('columnA', 'Field A'), $column('columnB', 'Field B')],
            ]])),
            self::scenario('layout-columns-3', 'Three-column row', self::GROUP_LAYOUT, self::buildForm([[
                [$column('columnA', 'Field A'), $column('columnB', 'Field B'), $column('columnC', 'Field C')],
            ]])),
            self::scenario('layout-heading', 'Heading block', self::GROUP_LAYOUT, self::form([[
                ['type' => Heading::class, 'label' => 'Section heading', 'level' => 'h3'],
            ]])),
            self::scenario('layout-html', 'HTML block', self::GROUP_LAYOUT, self::form([[
                ['type' => Html::class, 'content' => '<p>Arbitrary HTML, for a disclaimer or an embed.</p>'],
            ]])),
            self::scenario('layout-section', 'Section grouping', self::GROUP_LAYOUT, self::form([[
                ['type' => Text::class, 'handle' => 'firstGroupField', 'label' => 'Field in the first group'],
                ['type' => Section::class],
                ['type' => Text::class, 'handle' => 'secondGroupField', 'label' => 'Field in the second group'],
            ]])),
            // `representativeFields()` mixes plain fields with a fieldset one
            // (Checkboxes) and a file picker - `formable-form--labels-left`
            // (2.5/5.5) only converts the plain-field shape, so this is also
            // the gallery's check that the fieldset one is left alone rather
            // than silently misaligned.
            self::scenario(
                'layout-bordered',
                'Bordered form',
                self::GROUP_LAYOUT,
                self::form([self::representativeFields()]),
                ['class' => 'formable-form--bordered'],
            ),
            self::scenario(
                'layout-labels-left',
                'Labels left',
                self::GROUP_LAYOUT,
                self::form([self::representativeFields()]),
                ['class' => 'formable-form--labels-left'],
            ),
            // Same two variants, driven through `FormSettings::$appearance` /
            // `$labelPosition` (5.6) rather than the raw `options.class`
            // escape hatch above - proves the settings wire up to the same
            // CSS, not just that the CSS itself works.
            self::scenario(
                'layout-appearance-bordered',
                'Bordered (form setting)',
                self::GROUP_LAYOUT,
                self::form([self::representativeFields()], ['appearance' => 'bordered']),
            ),
            self::scenario(
                'layout-label-position-left',
                'Labels left (form setting)',
                self::GROUP_LAYOUT,
                self::form([self::representativeFields()], ['labelPosition' => 'left']),
            ),
        ];
    }

    /**
     * A 3-page form at each of the three `progressIndicator` settings, shown
     * on pages 1, 2 and 3 - 9 scenarios, so the indicator's position and
     * completed/current/upcoming styling can be checked everywhere it moves.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function progressScenarios(): array
    {
        $indicators = [
            FormSettings::PROGRESS_NONE => 'No indicator',
            FormSettings::PROGRESS_BAR => 'Progress bar',
            FormSettings::PROGRESS_STEPS => 'Numbered steps',
        ];

        $scenarios = [];

        foreach ($indicators as $indicator => $indicatorLabel) {
            $form = self::progressForm($indicator);

            foreach ([0, 1, 2] as $pageIndex) {
                $scenarios[] = self::scenario(
                    "progress-$indicator-page-" . ($pageIndex + 1),
                    "$indicatorLabel, page " . ($pageIndex + 1) . ' of 3',
                    self::GROUP_PROGRESS,
                    $form,
                    ['page' => $pageIndex],
                );
            }
        }

        // An 8-page form collapses the stepped indicator to its compact label
        // regardless of container width (`total >= 8`, `_progress.twig`) -
        // shown at the default width here specifically to prove that trigger
        // is independent of the narrow-container one, which every other
        // progress scenario above is already too short a form to reach.
        // See [[frontend-embeddability-review]] chunk 9 (L3).
        $manySteps = self::form(
            array_map(
                static fn(int $index): array => [['type' => Text::class, 'handle' => "stepField$index", 'label' => "Step field $index"]],
                range(1, 8),
            ),
            ['progressIndicator' => FormSettings::PROGRESS_STEPS],
        );
        $scenarios[] = self::scenario(
            'progress-steps-many',
            'Numbered steps, 8 pages (compact)',
            self::GROUP_PROGRESS,
            $manySteps,
            ['page' => 3],
        );

        return $scenarios;
    }

    private static function progressForm(string $progressIndicator): Form
    {
        return self::form([
            [['type' => Text::class, 'handle' => 'stepOneField', 'label' => 'Step one']],
            [['type' => Text::class, 'handle' => 'stepTwoField', 'label' => 'Step two']],
            [['type' => Text::class, 'handle' => 'stepThreeField', 'label' => 'Step three']],
        ], [
            'progressIndicator' => $progressIndicator,
        ]);
    }

    /**
     * First page · middle page · last page · review step, over a 3-page form
     * carrying every field type split evenly across its pages.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function multiPageScenarios(): array
    {
        $everyType = self::everyFieldType();
        $pageCount = 3;
        $perPage = (int)ceil(count($everyType) / $pageCount);

        $form = self::form(
            array_chunk($everyType, $perPage),
            ['showReviewPage' => true, 'progressIndicator' => FormSettings::PROGRESS_STEPS],
        );

        return [
            self::scenario('multi-page-first', 'First page', self::GROUP_MULTI_PAGE, $form, ['page' => 0]),
            self::scenario('multi-page-middle', 'Middle page', self::GROUP_MULTI_PAGE, $form, ['page' => 1]),
            self::scenario('multi-page-last', 'Last page', self::GROUP_MULTI_PAGE, $form, ['page' => 2]),
            self::scenario('multi-page-review', 'Review step', self::GROUP_MULTI_PAGE, $form, ['page' => 2, 'review' => true]),
        ];
    }

    /**
     * Error summary, success and closed - the three states that replace or
     * sit above the fields rather than styling one of them.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function messageScenarios(): array
    {
        $fields = [
            ['type' => Text::class, 'handle' => 'shortText', 'label' => 'Short text', 'required' => true],
            ['type' => Email::class, 'handle' => 'emailAddress', 'label' => 'Email address', 'required' => true],
        ];

        $errored = new Submission();
        $errored->addFieldError('shortText', 'This field is required.');
        $errored->addFieldError('emailAddress', 'Enter a valid email address.');

        $closedForm = self::form([$fields]);
        $closedForm->enabled = false;

        return [
            self::scenario('messages-error-summary', 'Error summary', self::GROUP_MESSAGES, self::form([$fields]), ['submission' => $errored]),
            [
                ...self::scenario('messages-success', 'Success', self::GROUP_MESSAGES, self::form([$fields], [
                    'successDetails' => "We'll reply within two business days.",
                ])),
                'flashSuccess' => true,
            ],
            self::scenario('messages-closed', 'Closed', self::GROUP_MESSAGES, $closedForm),
        ];
    }

    /**
     * The three button variants at the three sizes - swatches, not a form
     * control, so they ride on a lone `Html` field carrying the literal
     * markup - plus Next/Back/Submit as the nav actually places them, on a
     * bare 3-page form.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function buttonScenarios(): array
    {
        $variants = ['primary' => 'Primary', 'secondary' => 'Secondary', 'ghost' => 'Ghost'];
        $sizes = ['' => 'Default', 'sm' => 'Small', 'lg' => 'Large'];

        $scenarios = [];

        foreach ($variants as $variant => $variantLabel) {
            foreach ($sizes as $sizeClass => $sizeLabel) {
                $classes = trim("formable-button formable-button--$variant" . ($sizeClass !== '' ? " formable-button--$sizeClass" : ''));

                $scenarios[] = self::scenario(
                    "buttons-$variant-" . ($sizeClass !== '' ? $sizeClass : 'default'),
                    "$variantLabel, $sizeLabel",
                    self::GROUP_BUTTONS,
                    self::form([[
                        ['type' => Html::class, 'content' => "<button type=\"button\" class=\"$classes\">Button</button>"],
                    ]]),
                );
            }
        }

        $navForm = self::form([
            [['type' => Text::class, 'handle' => 'stepOneField', 'label' => 'Step one']],
            [['type' => Text::class, 'handle' => 'stepTwoField', 'label' => 'Step two']],
            [['type' => Text::class, 'handle' => 'stepThreeField', 'label' => 'Step three']],
        ]);

        $scenarios[] = self::scenario('buttons-next', 'Next, in place', self::GROUP_BUTTONS, $navForm, ['page' => 0]);
        $scenarios[] = self::scenario('buttons-back-next', 'Back and Next, in place', self::GROUP_BUTTONS, $navForm, ['page' => 1]);
        $scenarios[] = self::scenario('buttons-back-submit', 'Back and Submit, in place', self::GROUP_BUTTONS, $navForm, ['page' => 2]);

        foreach ($variants as $variant => $variantLabel) {
            $classes = "formable-button formable-button--$variant";

            $scenarios[] = self::scenario(
                "buttons-loading-$variant",
                "$variantLabel, loading",
                self::GROUP_BUTTONS,
                self::form([[
                    [
                        'type' => Html::class,
                        'content' => "<button type=\"button\" class=\"$classes\" aria-busy=\"true\"><span class=\"formable-button__label\">Button</span></button>",
                    ],
                ]]),
            );
        }

        return $scenarios;
    }

    /**
     * The all-fields form at each theme level, so 4.9's `data-formable-scheme`
     * work and the theme stylesheet itself can be checked together.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function themeLevelScenarios(): array
    {
        $form = self::form([self::everyFieldType()]);

        return [
            self::scenario('theme-none', 'No theme', self::GROUP_THEME_LEVELS, $form, ['theme' => 'none']),
            self::scenario('theme-contract', 'Contract only', self::GROUP_THEME_LEVELS, $form, ['theme' => 'contract']),
            self::scenario('theme-reset', 'Reset only', self::GROUP_THEME_LEVELS, $form, ['theme' => 'reset']),
            self::scenario('theme-default', 'Default theme', self::GROUP_THEME_LEVELS, $form, ['theme' => 'default']),
        ];
    }

    /**
     * The three `colorScheme` values (internal/decisions/0071), forced
     * through the real per-render option - not the frame's own manual
     * `scheme` toggle, which only simulates an ancestor forcing the
     * attribute for scenarios that need to fake OS state. `color-scheme-auto`
     * reuses `embed-light-host-dark-os`'s setup (a dark-forced `<html>` over
     * a literally white page) so the one thing visibly different from that
     * scenario is auto's self-painted background - the fix H2 needed.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function colorSchemeScenarios(): array
    {
        $form = self::form([self::representativeFields()]);

        return [
            self::scenario('color-scheme-light', 'Light', self::GROUP_COLOR_SCHEME, $form, ['colorScheme' => 'light']),
            self::scenario('color-scheme-dark', 'Dark', self::GROUP_COLOR_SCHEME, $form, ['colorScheme' => 'dark']),
            [
                ...self::scenario('color-scheme-auto', 'Auto, self-contained on a light host', self::GROUP_COLOR_SCHEME, $form, ['colorScheme' => 'auto']),
                'previewDefaults' => ['scheme' => 'dark', 'pageBackground' => 'white'],
            ],
        ];
    }

    /**
     * A branded light form, a branded dark form, the same light palette
     * pinned inside a dark-forced ancestor, and a washed-out brand colour.
     *
     * The third scenario is internal/decisions/0072's path: an ordinary
     * light/dark pair only ever exercises the media-query scheme block for
     * each colour, never the attribute one - pinning the form to `light`
     * inside an ancestor forced to `dark` is what makes the *attribute*
     * block the one that has to match, which is the proof 0091
     * §3 needed that the `--formable-brand` fallback was added to all four
     * scheme blocks, not just the two a media query alone would cover.
     *
     * The fourth exercises {@see \bytesof\formable\helpers\Color::readableTextOn()}:
     * a brand colour too light for white text shows whether
     * {@see \bytesof\formable\models\Palette::toCssDeclarations()}'s computed
     * `--formable-button-primary-text` / `--formable-progress-text` actually
     * flip to something readable, rather than the raw picked colour just
     * being trusted.
     *
     * The palette rides `FormSettings::$palette*`, the same fields the
     * builder's Pro panel writes to - {@see \bytesof\formable\services\Rendering::resolvePalette()}
     * reads a scenario's palette from there exactly as `layout-appearance-bordered`
     * above reads `FormSettings::$appearance`.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function paletteScenarios(): array
    {
        $light = [
            'paletteBrand' => '#7c3aed',
            'paletteSurface' => '#f5f3ff',
            'paletteText' => '#2e1065',
            'paletteBorder' => '#ddd6fe',
            'paletteRadius' => 'round',
            'paletteButtonStyle' => 'filled',
        ];

        $dark = [
            'paletteBrand' => '#a78bfa',
            'paletteSurface' => '#211c3d',
            'paletteText' => '#ede9fe',
            'paletteBorder' => '#4c3a6e',
            'paletteRadius' => 'sharp',
            'paletteButtonStyle' => 'outline',
        ];

        // Brand only, deliberately left too light for white text - surface,
        // text and border stay unset so nothing but the brand seed's own
        // computed fallbacks is on screen.
        $lowContrast = [
            'paletteBrand' => '#fde047',
        ];

        return [
            self::scenario('palette-light', 'Branded, light', self::GROUP_PALETTE, self::paletteForm($light), ['colorScheme' => 'light', 'page' => 1]),
            self::scenario('palette-dark', 'Branded, dark', self::GROUP_PALETTE, self::paletteForm($dark), ['colorScheme' => 'dark', 'page' => 1]),
            [
                ...self::scenario(
                    'palette-pinned-light-in-dark-ancestor',
                    'Branded, pinned light in a dark-forced ancestor',
                    self::GROUP_PALETTE,
                    self::paletteForm($light),
                    ['colorScheme' => 'light', 'page' => 1],
                ),
                'previewDefaults' => ['scheme' => 'dark'],
            ],
            self::scenario('palette-low-contrast', 'Washed-out brand colour', self::GROUP_PALETTE, self::paletteForm($lowContrast), ['page' => 1]),
        ];
    }

    /**
     * A 3-page, numbered-steps form - the shape that puts the palette's
     * computed button and progress text on screen alongside the primary
     * button, not just the button on its own.
     *
     * @param array<string, string> $paletteSettings
     */
    private static function paletteForm(array $paletteSettings): Form
    {
        return self::form([
            [
                ['type' => Text::class, 'handle' => 'shortText', 'label' => 'Short text', 'required' => true],
                ['type' => Dropdown::class, 'handle' => 'country', 'label' => 'Country', 'options' => self::options('Kenya', 'Norway', 'Peru')],
            ],
            [
                ['type' => Textarea::class, 'handle' => 'longText', 'label' => 'Message'],
                ['type' => Checkboxes::class, 'handle' => 'interests', 'label' => 'Interests', 'options' => self::options('News', 'Events', 'Offers')],
            ],
            [
                ['type' => FileUpload::class, 'handle' => 'attachment', 'label' => 'Attachment'],
            ],
        ], [
            'progressIndicator' => FormSettings::PROGRESS_STEPS,
            ...$paletteSettings,
        ]);
    }

    /**
     * The contexts a generic toggle can't reproduce on its own - RTL needs
     * real right-to-left content to mean anything, "light host, dark OS"
     * needs the frame to stop deriving its page background from the form's
     * own token ([[frontend-embeddability-review]] chunk 1, step 3-4), and
     * the flex-column/grid hosts need a definite host width for the form to
     * fill or fail to fill (chunk 14, N3). `width`/`host`/`dir`/`chrome` are
     * otherwise frame-level query parameters any scenario can be viewed
     * under - see
     * {@see \bytesof\formable\controllers\PreviewController::resolvePreviewDefaults()}.
     * Each entry's `previewDefaults` is just that: a starting point the
     * gallery's own controls can still override.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function embedScenarios(): array
    {
        $arabicFields = [
            ['type' => Text::class, 'handle' => 'fullName', 'label' => 'الاسم الكامل', 'instructions' => 'الاسم كما يظهر في جواز السفر.', 'required' => true],
            ['type' => Dropdown::class, 'handle' => 'country', 'label' => 'البلد', 'options' => self::options('مصر', 'المغرب', 'الأردن')],
            ['type' => Checkboxes::class, 'handle' => 'interests', 'label' => 'الاهتمامات', 'options' => self::options('الأخبار', 'الفعاليات', 'العروض')],
            ['type' => Textarea::class, 'handle' => 'message', 'label' => 'رسالة'],
        ];

        return [
            [
                ...self::scenario('embed-rtl', 'RTL form (Arabic)', self::GROUP_EMBED, self::form([$arabicFields])),
                'previewDefaults' => ['dir' => 'rtl'],
            ],
            [
                ...self::scenario('embed-light-host-dark-os', 'Light host, dark OS', self::GROUP_EMBED, self::form([self::representativeFields()])),
                'previewDefaults' => ['scheme' => 'dark', 'pageBackground' => 'white'],
            ],
            [
                ...self::scenario('embed-dialog', 'Width-to-content dialog', self::GROUP_EMBED, self::buildForm([[
                    [['type' => Text::class, 'handle' => 'columnA', 'label' => 'Field A'], ['type' => Text::class, 'handle' => 'columnB', 'label' => 'Field B']],
                    [['type' => Textarea::class, 'handle' => 'longText', 'label' => 'Message']],
                ]])),
                'previewDefaults' => ['chrome' => 'dialog'],
            ],
            [
                ...self::scenario('embed-sidebar', 'Zero-padding sidebar', self::GROUP_EMBED, self::form([self::representativeFields()])),
                'previewDefaults' => ['chrome' => 'sidebar', 'width' => '280'],
            ],
            [
                ...self::scenario('embed-flex-column', 'Flex-column host', self::GROUP_EMBED, self::form([self::representativeFields()])),
                'previewDefaults' => ['chrome' => 'flex-column', 'width' => '600'],
            ],
            [
                ...self::scenario('embed-grid', 'Grid host', self::GROUP_EMBED, self::form([self::representativeFields()])),
                'previewDefaults' => ['chrome' => 'grid', 'width' => '600'],
            ],
        ];
    }

    /**
     * The five field types a state has to be shown on: different enough in
     * shape (plain input, textarea, select, checkbox group, file picker) that
     * a state's styling can't hide behind just one of them.
     *
     * @return array<int, array<string, mixed>>
     */
    private static function representativeFields(): array
    {
        return [
            ['type' => Text::class, 'handle' => 'shortText', 'label' => 'Short text'],
            ['type' => Textarea::class, 'handle' => 'longText', 'label' => 'Message'],
            ['type' => Dropdown::class, 'handle' => 'country', 'label' => 'Country', 'options' => self::options('Kenya', 'Norway', 'Peru')],
            ['type' => Checkboxes::class, 'handle' => 'interests', 'label' => 'Interests', 'options' => self::options('News', 'Events', 'Offers')],
            ['type' => FileUpload::class, 'handle' => 'attachment', 'label' => 'Attachment'],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private static function scenario(string $key, string $label, string $group, Form $form, array $options = []): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'group' => $group,
            'form' => $form,
            'options' => $options,
        ];
    }

    /**
     * A single-page-per-entry form, one field per row - the shape every group
     * but Layout needs. Each top-level entry is one page's field list.
     *
     * @param array<int, array<int, array<string, mixed>>> $pages
     * @param array<string, mixed> $settings
     */
    private static function form(array $pages, array $settings = []): Form
    {
        return self::buildForm(
            array_map(
                static fn(array $fields): array => array_map(static fn(array $field): array => [$field], $fields),
                $pages,
            ),
            $settings,
        );
    }

    /**
     * Builds a form directly from a pages/rows/fields layout, never saved. A
     * row is an array of field configs, so a row can carry more than one
     * field - the shape the column-layout scenarios need and {@see form()}'s
     * one-field-per-row convenience hides.
     *
     * @param array<int, array<int, array<int, array<string, mixed>>>> $pages
     * @param array<string, mixed> $settings
     */
    private static function buildForm(array $pages, array $settings = []): Form
    {
        $form = new Form();
        $form->title = 'Preview';
        $form->handle = 'formablePreview';
        $form->enabled = true;

        $form->setPages(array_map(
            static fn(array $rows, int $pageIndex): array => [
                'id' => "page-$pageIndex",
                'label' => 'Page ' . ($pageIndex + 1),
                'settings' => [],
                'rows' => array_map(
                    static fn(array $fields, int $rowIndex): array => [
                        'id' => "row-$pageIndex-$rowIndex",
                        'fields' => array_map(
                            static fn(array $field, int $fieldIndex): array => ['id' => "field-$pageIndex-$rowIndex-$fieldIndex"] + $field,
                            $fields,
                            array_keys($fields),
                        ),
                    ],
                    $rows,
                    array_keys($rows),
                ),
            ],
            $pages,
            array_keys($pages),
        ));

        $form->setSettings([
            'minSubmitTime' => 0,
        ] + $settings);

        return $form;
    }

    /**
     * Whether a scenario settles its own colour scheme, so the frame's
     * `scheme` toggle must not reach the form.
     *
     * A scenario pins one in two ways: a `colorScheme` render option (the
     * Colour scheme and Palette groups), or a `scheme` preview default, which
     * is how `embed-light-host-dark-os` and its kin simulate a dark-forced
     * ancestor around a form that keeps its own scheme. Every other scenario
     * is scheme-neutral, and the frame shows it in whichever scheme it is
     * asked for.
     *
     * @param array<string, mixed> $scenario
     */
    public static function pinsScheme(array $scenario): bool
    {
        return isset($scenario['options']['colorScheme']) || isset($scenario['previewDefaults']['scheme']);
    }

    /**
     * @return array<int, array<string, string>>
     */
    private static function options(string ...$labels): array
    {
        return array_map(static fn(string $label): array => [
            'label' => $label,
            'value' => strtolower(str_replace(' ', '-', $label)),
        ], $labels);
    }

    private static function shortName(string $class): string
    {
        $parts = explode('\\', $class);

        return end($parts);
    }
}
