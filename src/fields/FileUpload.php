<?php

declare(strict_types=1);

namespace bytesof\formable\fields;

use bytesof\formable\base\FormField;
use Craft;
use craft\helpers\Assets;
use Throwable;

/**
 * File upload into a configured volume.
 *
 * The stored value is a list of asset IDs. The upload itself (moving temp
 * files into the volume, enforcing MIME types) happens in the submission
 * pipeline; this class owns the constraints that pipeline enforces.
 *
 * @internal
 */
final class FileUpload extends FormField
{
    public ?string $volumeUid = null;

    /**
     * Subfolder within the volume. May contain Twig, e.g.
     * `submissions/{{ now|date('Y-m') }}`.
     */
    public string $subfolder = '';

    /**
     * Allowed Craft asset kinds (`image`, `pdf`, …). Empty means any kind the
     * site's `allowedFileExtensions` config permits.
     *
     * @var array<int, string>
     */
    public array $allowedKinds = [];

    /**
     * Per-file size cap, in megabytes.
     */
    public ?float $maxFileSize = null;

    public int $limit = 1;

    public static function displayName(): string
    {
        return Craft::t('formable', 'File Upload');
    }

    public static function icon(): string
    {
        return 'upload';
    }

    public static function group(): string
    {
        return self::GROUP_ADVANCED;
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['volumeUid'], 'required'];
        $rules[] = [['volumeUid', 'subfolder'], 'string'];
        $rules[] = [['limit'], 'integer', 'min' => 1];
        $rules[] = [['maxFileSize'], 'number', 'min' => 0.01];
        $rules[] = [['allowedKinds'], 'each', 'rule' => ['in', 'range' => array_keys(self::fileKinds())]];

        return $rules;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function defineSettingsSchema(): array
    {
        return [
            [
                'name' => 'volumeUid',
                'type' => 'volumeSelect',
                'label' => Craft::t('formable', 'Upload Location'),
                'required' => true,
            ],
            [
                'name' => 'subfolder',
                'type' => 'text',
                'label' => Craft::t('formable', 'Subfolder'),
                'instructions' => Craft::t('formable', 'Optional path within the volume. Twig is supported, e.g. {example}.', [
                    'example' => 'submissions/{{ now|date(\'Y-m\') }}',
                ]),
            ],
            [
                'name' => 'allowedKinds',
                'type' => 'checkboxGroup',
                'label' => Craft::t('formable', 'Allowed File Types'),
                'instructions' => Craft::t('formable', 'Leave all unchecked to allow any type permitted by the site config.'),
                'options' => array_map(
                    static fn(string $kind, array $info): array => [
                        'value' => $kind,
                        'label' => $info['label'] ?? $kind,
                    ],
                    array_keys(self::fileKinds()),
                    array_values(self::fileKinds()),
                ),
            ],
            [
                'name' => 'maxFileSize',
                'type' => 'number',
                'label' => Craft::t('formable', 'Max File Size (MB)'),
                'min' => 0.01,
            ],
            [
                'name' => 'limit',
                'type' => 'number',
                'label' => Craft::t('formable', 'File Limit'),
                'default' => 1,
                'min' => 1,
            ],
        ];
    }

    /**
     * Craft's file-kind map, keyed by kind.
     *
     * Wrapped because Craft resolves the map through the general config,
     * which isn't available outside a booted application - a field's rules
     * shouldn't blow up when they're inspected from a console or test
     * context.
     *
     * @return array<string, array<string, mixed>>
     */
    private static function fileKinds(): array
    {
        static $kinds = null;

        if ($kinds === null) {
            try {
                $kinds = Assets::getFileKinds();
            } catch (Throwable) {
                $kinds = [];
            }
        }

        return $kinds;
    }

    /**
     * The per-file size cap in bytes, for the upload handler and the
     * client-side check.
     */
    public function getMaxFileSizeInBytes(): ?int
    {
        return $this->maxFileSize !== null ? (int)round($this->maxFileSize * 1024 * 1024) : null;
    }

    /**
     * @inheritdoc
     */
    public function extraDescribedBy(string $inputId): array
    {
        if ($this->limit <= 1 && $this->maxFileSize === null) {
            return [];
        }

        return ["{$inputId}-hint"];
    }

    /**
     * File extensions matching the allowed kinds, for the input's `accept`
     * attribute. Empty means no restriction beyond the site config.
     *
     * @return array<int, string>
     */
    public function getAllowedExtensions(): array
    {
        $extensions = [];
        $kinds = self::fileKinds();

        foreach ($this->allowedKinds as $kind) {
            foreach ($kinds[$kind]['extensions'] ?? [] as $extension) {
                $extensions[] = $extension;
            }
        }

        return array_values(array_unique($extensions));
    }

    /**
     * @return array<int, array<mixed>>
     */
    protected function defineValueValidationRules(): array
    {
        return [
            ['each', 'rule' => ['integer', 'min' => 1]],
        ];
    }

    /**
     * @return array<int, string>
     */
    protected function findValueErrors(mixed $value): array
    {
        if (is_array($value) && count($value) > $this->limit) {
            return [
                Craft::t('formable', '{label} accepts at most {limit, plural, one{# file} other{# files}}.', [
                    'label' => $this->label,
                    'limit' => $this->limit,
                ]),
            ];
        }

        return [];
    }

    public function normalizeValue(mixed $value): mixed
    {
        if ($value === null || $value === '') {
            return [];
        }

        $ids = array_map(
            static fn(mixed $id): int => is_numeric($id) ? (int)$id : 0,
            is_array($value) ? array_values($value) : [$value],
        );

        return array_values(array_filter($ids, static fn(int $id): bool => $id > 0));
    }

    public function serializeValue(mixed $value): mixed
    {
        return $this->normalizeValue($value);
    }

    /**
     * @inheritdoc
     */
    public function getRelatedElementIds(mixed $value): array
    {
        // The normalized value already *is* the list of asset ids, in upload
        // order. An upload relates a submission to a Craft element just as an
        // entry picker does - the asset is simply one this form created.
        $ids = $this->normalizeValue($value);

        /** @var array<int, int> */
        return is_array($ids) ? $ids : [];
    }

    public function getDefaultValue(): mixed
    {
        return [];
    }
}
