<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\db\Table;
use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\fields\FileUpload;
use bytesof\formable\Plugin;
use Craft;
use craft\db\Query;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use Throwable;
use yii\base\Component;
use yii\web\UploadedFile;

/**
 * Moves uploaded files into the volume a File Upload field points at.
 *
 * Everything the field's settings promise is re-checked here against the file
 * on disk: the browser's `accept` attribute, the `multiple` cap and any
 * client-side size check are conveniences, not constraints - a hand-rolled
 * POST bypasses all three.
 *
 * @internal
 */
final class Uploads extends Component
{
    /**
     * Moves the files uploaded with one page of a form into their volumes and
     * swaps the asset IDs in as that page's field values.
     *
     * Scoped to the fields on the page being posted, so a multi-page form
     * doesn't re-scan (and re-reject) the upload fields on pages the submitter
     * isn't looking at. Anything the volume refused lands on the submission as
     * a field error, so it reads back exactly like a validation failure.
     */
    public function handlePageUploads(Form $form, Submission $submission, int $pageIndex): void
    {
        // Localized so a field error added below (a rejected file, a missing
        // volume) names the field the way the submitter actually saw it.
        $pages = Plugin::getInstance()->getTranslations()->localizePages($form);
        $pageFields = Plugin::getInstance()->getFields()->getValueFieldsFromLayout($pages, $pageIndex);

        foreach ($pageFields as $handle => $field) {
            if (!$field instanceof FileUpload) {
                continue;
            }

            $result = $this->handleUploads($form, $field);

            foreach ($result['errors'] as $message) {
                $submission->addFieldError($handle, $message);
            }

            if ($result['ids'] !== []) {
                $submission->setValue($handle, $result['ids']);
            }
        }
    }

    /**
     * Whether any file was actually posted for one of the page's File Upload
     * fields - what {@see \bytesof\formable\controllers\SubmissionsController::actionSubmit()}
     * checks before spending the upload rate-limit bucket, so a page with no
     * File Upload field, or one posted with nothing attached, never counts
     * against it.
     */
    public function hasPageUploads(Form $form, int $pageIndex): bool
    {
        return $this->getPendingUploadHandles($form, $pageIndex) !== [];
    }

    /**
     * The handles of the page's File Upload fields that actually have a file
     * posted for them.
     *
     * What {@see \bytesof\formable\controllers\SubmissionsController::actionSubmit()}
     * excludes from the pre-upload validation check: a required File Upload
     * field's own requiredness can't be judged from its stored value before
     * the file behind it has been moved into the volume, so it is checked
     * again, against the real asset ids, once {@see handlePageUploads()} has
     * run.
     *
     * @return array<int, string>
     */
    public function getPendingUploadHandles(Form $form, int $pageIndex): array
    {
        $pageFields = Plugin::getInstance()->getFields()->getValueFieldsFromLayout($form->getPages(), $pageIndex);
        $handles = [];

        foreach ($pageFields as $handle => $field) {
            if ($field instanceof FileUpload && $this->getUploadedFiles($field) !== []) {
                $handles[] = $handle;
            }
        }

        return $handles;
    }

    /**
     * Reads the uploaded files for a field and turns them into assets.
     *
     * @return array{ids: array<int, int>, errors: array<int, string>}
     */
    public function handleUploads(Form $form, FileUpload $field): array
    {
        $files = $this->getUploadedFiles($field);

        if ($files === []) {
            return ['ids' => [], 'errors' => []];
        }

        // Rejecting the whole upload (rather than silently keeping the first
        // N) is the honest answer - the submitter picked those files on
        // purpose and should be told which ones didn't make it.
        if (count($files) > $field->limit) {
            return [
                'ids' => [],
                'errors' => [
                    Craft::t('formable', '{label} accepts at most {limit, plural, one{# file} other{# files}}.', [
                        'label' => $field->label,
                        'limit' => $field->limit,
                    ]),
                ],
            ];
        }

        $folderId = $this->resolveFolderId($form, $field);

        if ($folderId === null) {
            return [
                'ids' => [],
                'errors' => [
                    Craft::t('formable', 'The upload location for “{label}” is not available.', [
                        'label' => $field->label,
                    ]),
                ],
            ];
        }

        $ids = [];
        $errors = [];

        foreach ($files as $file) {
            $fileErrors = $this->validateFile($field, $file);

            if ($fileErrors !== []) {
                $errors = array_merge($errors, $fileErrors);

                continue;
            }

            $assetId = $this->createAsset($file, $folderId);

            if ($assetId === null) {
                $errors[] = Craft::t('formable', '“{filename}” could not be uploaded.', [
                    'filename' => $file->name,
                ]);

                continue;
            }

            $ids[] = $assetId;
        }

        return ['ids' => $ids, 'errors' => $errors];
    }

    /**
     * @return array<int, UploadedFile>
     */
    public function getUploadedFiles(FileUpload $field): array
    {
        if ($field->handle === '') {
            return [];
        }

        // Yii flattens `$_FILES` into bracketed keys, so this covers both the
        // single-file input (`fields[cv]`) and the multiple one (`fields[cv][]`).
        return array_values(array_filter(
            UploadedFile::getInstancesByName("fields[$field->handle]"),
            static fn(UploadedFile $file): bool => $file->name !== '' && $file->size > 0,
        ));
    }

    /**
     * Checks one file against the field's constraints and the site's own
     * allowed-extension list.
     *
     * @return array<int, string>
     */
    public function validateFile(FileUpload $field, UploadedFile $file): array
    {
        if ($file->getHasError()) {
            return [
                Craft::t('formable', '“{filename}” could not be uploaded: {error}', [
                    'filename' => $file->name,
                    'error' => $this->uploadErrorMessage($file->error),
                ]),
            ];
        }

        $errors = [];
        $extension = strtolower($file->getExtension());
        $maxBytes = $field->getMaxFileSizeInBytes();

        if ($maxBytes !== null && $file->size > $maxBytes) {
            $errors[] = Craft::t('formable', '“{filename}” is larger than the {size} MB limit.', [
                'filename' => $file->name,
                'size' => $field->maxFileSize,
            ]);
        }

        $siteExtensions = array_map('strtolower', Craft::$app->getConfig()->getGeneral()->allowedFileExtensions);

        if (!in_array($extension, $siteExtensions, true)) {
            $errors[] = Craft::t('formable', '“{filename}” is not an allowed file type.', [
                'filename' => $file->name,
            ]);

            // No point sniffing content the site would refuse to store anyway.
            return $errors;
        }

        $allowedExtensions = $field->getAllowedExtensions();

        if ($allowedExtensions !== [] && !in_array($extension, array_map('strtolower', $allowedExtensions), true)) {
            $errors[] = Craft::t('formable', '“{filename}” is not an allowed file type.', [
                'filename' => $file->name,
            ]);

            return $errors;
        }

        if (!$this->contentMatchesExtension($file, $extension)) {
            $errors[] = Craft::t('formable', '“{filename}” does not match its file extension.', [
                'filename' => $file->name,
            ]);
        }

        return $errors;
    }

    /**
     * Whether the file's sniffed content agrees with its extension.
     *
     * This is what stops a PHP script renamed to `.png` from landing in a
     * public volume - the extension check above is satisfied by the rename,
     * so the file's actual bytes have to be consulted.
     *
     * Fails closed on content whose type maps to no known extension at all:
     * `text/x-php` and friends map to nothing, which is precisely the case
     * worth rejecting. Only a sniffer that can't produce a type at all is
     * treated as "can't tell", since then there's nothing to compare against.
     */
    private function contentMatchesExtension(UploadedFile $file, string $extension): bool
    {
        try {
            $mimeType = FileHelper::getMimeType($file->tempName, null, false);

            if ($mimeType === null) {
                return true;
            }

            $expected = array_map('strtolower', FileHelper::getExtensionsByMimeType($mimeType));
        } catch (Throwable $e) {
            Craft::warning(
                sprintf('Could not determine the type of an uploaded file: %s', $e->getMessage()),
                __METHOD__,
            );

            return true;
        }

        return in_array($extension, $expected, true);
    }

    /**
     * Resolves (creating if needed) the folder a field's uploads belong in.
     *
     * The subfolder setting may contain Twig, evaluated against the form so
     * paths like `submissions/{{ object.handle }}/{{ now|date('Y-m') }}` work.
     */
    public function resolveFolderId(Form $form, FileUpload $field): ?int
    {
        if ($field->volumeUid === null || $field->volumeUid === '') {
            return null;
        }

        $volume = Craft::$app->getVolumes()->getVolumeByUid($field->volumeUid);

        if ($volume === null) {
            return null;
        }

        try {
            $assets = Craft::$app->getAssets();
            $rootFolder = $assets->getRootFolderByVolumeId($volume->id);

            if ($rootFolder === null || $rootFolder->id === null) {
                return null;
            }

            $subfolder = $this->renderSubfolder($form, $field);

            if ($subfolder === '') {
                return $rootFolder->id;
            }

            return $assets->ensureFolderByFullPathAndVolume("$subfolder/", $volume)->id;
        } catch (Throwable $e) {
            Craft::error(
                sprintf('Could not resolve the Formable upload folder: %s', $e->getMessage()),
                __METHOD__,
            );

            return null;
        }
    }

    /**
     * The subfolder setting with Twig resolved and each segment sanitized.
     */
    private function renderSubfolder(Form $form, FileUpload $field): string
    {
        // A subfolder that wouldn't render falls back to no subfolder rather
        // than to its source: the volume root is a worse place for the file,
        // but a path with `{{ … }}` in it is not a place at all.
        $rendered = Plugin::getInstance()->getRendering()->renderObjectString(
            $field->subfolder,
            $form,
            Rendering::ON_ERROR_EMPTY,
            sprintf('subfolder for field “%s”', $field->handle),
        );

        $segments = array_filter(
            array_map(
                static fn(string $segment): string => Assets::prepareAssetName($segment, false, true),
                explode('/', $rendered),
            ),
            static fn(string $segment): bool => $segment !== '' && $segment !== '.' && $segment !== '..',
        );

        return implode('/', $segments);
    }

    /**
     * Records a stored submission as the owner of the uploads in its File
     * Upload fields, so they can be deleted along with it.
     *
     * Only an unclaimed upload is taken, so a second submission can never
     * adopt another's file - a File Upload value only comes from a real upload
     * (internal/decisions/0097), and this keeps the ownership record as strict
     * as that rule even if the value were ever set another way. An upload a
     * submission later drops stays with it until the submission itself goes.
     */
    public function claimUploads(Submission $submission): void
    {
        if ($submission->id === null) {
            return;
        }

        $assetIds = [];

        foreach ($submission->getFormFields() as $handle => $field) {
            if ($field instanceof FileUpload) {
                array_push($assetIds, ...$field->getRelatedElementIds($submission->getValue($handle)));
            }
        }

        if ($assetIds === []) {
            return;
        }

        Db::update(Table::UPLOADS, ['submissionId' => $submission->id], [
            'assetId' => array_values(array_unique($assetIds)),
            'submissionId' => null,
        ]);
    }

    /**
     * Deletes the uploads whose owning submission no longer exists, returning
     * how many assets were deleted.
     *
     * Keyed off the missing submission row rather than a delete event, because
     * Craft's garbage collection hard-deletes a trashed submission with a raw
     * DELETE that fires none. A submission in the trash still has its row, so
     * its files survive until it is restored or purged for good.
     *
     * With {@see \bytesof\formable\models\Settings::$deleteUploadsWithSubmissions}
     * off, the ownership record is dropped and the file left where it is. An
     * asset that fails to delete keeps its record, so the next run retries it.
     *
     * @param int|null $submissionId Limit the sweep to one deleted submission.
     */
    public function deleteOrphanedUploads(?int $submissionId = null): int
    {
        $query = (new Query())
            ->select(['u.id', 'u.assetId'])
            ->from(['u' => Table::UPLOADS])
            ->leftJoin(['s' => Table::SUBMISSIONS], '[[s.id]] = [[u.submissionId]]')
            ->where(['not', ['u.submissionId' => null]])
            ->andWhere(['s.id' => null]);

        if ($submissionId !== null) {
            $query->andWhere(['u.submissionId' => $submissionId]);
        }

        $deleteFiles = Plugin::getInstance()->getSettings()->deleteUploadsWithSubmissions;
        $elements = Craft::$app->getElements();
        $deleted = 0;

        foreach ($query->all() as $row) {
            if ($deleteFiles) {
                $asset = Asset::find()
                    ->id((int)$row['assetId'])
                    ->site('*')
                    ->unique()
                    ->status(null)
                    ->trashed(null)
                    ->one();

                if ($asset !== null) {
                    if (!$elements->deleteElement($asset, true)) {
                        Craft::warning(
                            sprintf('Could not delete Formable upload %d with its submission.', $asset->id),
                            __METHOD__,
                        );

                        continue;
                    }

                    $deleted++;
                }
            }

            Db::delete(Table::UPLOADS, ['id' => $row['id']]);
        }

        return $deleted;
    }

    /**
     * Creates the asset element, returning its ID.
     *
     * The temp file is moved rather than copied (`Asset::$avoidFilenameConflicts`
     * renames instead of overwriting), so two submitters uploading `cv.pdf`
     * don't clobber each other.
     */
    private function createAsset(UploadedFile $file, int $folderId): ?int
    {
        try {
            $folder = Craft::$app->getAssets()->getFolderById($folderId);

            if ($folder === null) {
                return null;
            }

            $tempPath = Assets::tempFilePath($file->getExtension());
            $file->saveAs($tempPath, false);

            $asset = new Asset();
            $asset->tempFilePath = $tempPath;
            $asset->setFilename(Assets::prepareAssetName($file->name));
            $asset->newFolderId = $folderId;
            $asset->setVolumeId($folder->volumeId);
            $asset->avoidFilenameConflicts = true;
            $asset->setScenario(Asset::SCENARIO_CREATE);

            if (!Craft::$app->getElements()->saveElement($asset) || $asset->id === null) {
                Craft::warning(
                    sprintf('Could not save a Formable upload: %s', implode(', ', $asset->getFirstErrors())),
                    __METHOD__,
                );

                return null;
            }

            Db::insert(Table::UPLOADS, ['assetId' => $asset->id]);

            return $asset->id;
        } catch (Throwable $e) {
            Craft::error(
                sprintf('Could not save a Formable upload: %s', $e->getMessage()),
                __METHOD__,
            );

            return null;
        }
    }

    private function uploadErrorMessage(int $error): string
    {
        return match ($error) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => Craft::t('formable', 'the file is too large'),
            UPLOAD_ERR_PARTIAL => Craft::t('formable', 'the upload was interrupted'),
            UPLOAD_ERR_NO_TMP_DIR, UPLOAD_ERR_CANT_WRITE => Craft::t('formable', 'the server could not store it'),
            default => Craft::t('formable', 'an unknown error occurred'),
        };
    }
}
