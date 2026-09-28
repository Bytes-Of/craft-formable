<?php

declare(strict_types=1);

namespace bytesof\formable\console\controllers;

use bytesof\formable\Plugin;
use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use craft\helpers\FileHelper;
use craft\helpers\Json;
use Throwable;
use yii\console\ExitCode;

/**
 * Manages Formable forms.
 *
 * `export`/`import` move a form's definition - layout, settings, notifications,
 * per-site translations and integration mappings (never provider secrets) -
 * between installs as JSON,
 * for a dev→prod deployment workflow:
 *
 *     php craft formable/forms/export contact
 *     php craft formable/forms/import contact.json --dry-run
 *
 * @internal
 */
final class FormsController extends Controller
{
    public $defaultAction = 'list';

    /**
     * Where `export` writes. A directory receives `<handle>.json`; anything else
     * is used as the filename. Defaults to `<handle>.json` in the working
     * directory.
     */
    public ?string $path = null;

    /**
     * Overwrite without asking: an existing export file, or an existing form on
     * import. Applies to `export` and `import`.
     */
    public bool $force = false;

    /**
     * Parse, validate and report what `import` would do without writing.
     */
    public bool $dryRun = false;

    /**
     * @param string $actionID
     * @return array<int, string>
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'export') {
            $options[] = 'path';
            $options[] = 'force';
        }

        if ($actionID === 'import') {
            $options[] = 'force';
            $options[] = 'dryRun';
        }

        return $options;
    }

    /**
     * Lists all forms.
     */
    public function actionList(): int
    {
        $forms = Plugin::getInstance()->getForms()->getAllForms();

        if ($forms === []) {
            $this->stdout("No forms exist yet.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        $this->table(
            ['ID', 'Name', 'Handle', 'Enabled'],
            array_map(
                static fn($form) => [$form->id, $form->title, $form->handle, $form->enabled ? 'yes' : 'no'],
                $forms,
            ),
        );

        return ExitCode::OK;
    }

    /**
     * Exports a form’s definition as JSON.
     */
    public function actionExport(string $handle): int
    {
        $form = Plugin::getInstance()->getForms()->getFormByHandle($handle);

        if ($form === null) {
            $this->stderr("No form with the handle “{$handle}” exists.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $path = $this->resolveExportPath($handle);

        if (is_file($path) && !$this->force) {
            $this->stderr("A file already exists at $path. Pass --force to overwrite it.\n", Console::FG_YELLOW);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $json = Json::encode(
            Plugin::getInstance()->getFormTransfer()->exportForm($form),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        try {
            FileHelper::writeToFile($path, $json);
        } catch (Throwable $e) {
            $this->stderr("Could not write to $path: {$e->getMessage()}\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout("Exported “{$form->title}” to $path\n", Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Imports a form definition from a JSON file, creating a new form or
     * updating an existing one with the same handle.
     */
    public function actionImport(string $path): int
    {
        if (!is_file($path)) {
            $this->stderr("No file exists at $path.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            $this->stderr("Could not read $path.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $data = Json::decodeIfJson($contents);

        if (!is_array($data)) {
            $this->stderr("$path is not a valid Formable export.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $transfer = Plugin::getInstance()->getFormTransfer();
        $result = $transfer->parseImport($data);
        $existing = $result->handle !== ''
            ? Plugin::getInstance()->getForms()->getFormByHandle($result->handle)
            : null;
        $verb = $existing !== null ? 'update' : 'create';

        $this->stdout("Handle:        {$result->handle}\n");
        $this->stdout("Title:         {$result->title}\n");
        $this->stdout("Action:        $verb\n");
        $this->stdout('Pages:         ' . count($result->pages) . "\n");
        $this->stdout('Fields:        ' . $result->fieldCount() . "\n");
        $this->stdout('Notifications: ' . count($result->notifications) . "\n");
        $this->stdout('Translations:  ' . count($result->translations) . " site(s)\n");

        // Checked here rather than in parseImport(), which stays database-free:
        // which integrations exist, and are switched on, is this install's
        // answer, not the file's.
        $errors = [
            ...$result->errors,
            ...Plugin::getInstance()->getIntegrations()->validateFieldReferences(
                $result->settings->integrations,
                Plugin::getInstance()->getLayout()->getFieldHandles($result->pages),
            ),
        ];

        if ($errors !== []) {
            $this->stderr("\nThe import is invalid:\n", Console::FG_RED);

            foreach ($errors as $error) {
                $this->stderr("  - $error\n", Console::FG_RED);
            }

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->dryRun) {
            $this->stdout("\nDry run - nothing was written.\n", Console::FG_CYAN);

            return ExitCode::OK;
        }

        if ($existing !== null && !$this->force) {
            $this->stderr(
                "\nA form with the handle “{$result->handle}” already exists. Pass --force to overwrite it.\n",
                Console::FG_YELLOW,
            );

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $form = $transfer->applyImport($result);

        if ($form->hasErrors()) {
            $this->stderr("\nThe form could not be saved:\n", Console::FG_RED);

            foreach ($form->getFirstErrors() as $error) {
                $this->stderr("  - $error\n", Console::FG_RED);
            }

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(
            "\n" . ($verb === 'create' ? 'Created' : 'Updated') . " “{$form->title}”.\n",
            Console::FG_GREEN,
        );

        return ExitCode::OK;
    }

    /**
     * The file `export` writes to: the `--path` option if given (a directory
     * receives `<handle>.json`), otherwise `<handle>.json` in the working
     * directory.
     */
    private function resolveExportPath(string $handle): string
    {
        $filename = "$handle.json";

        if ($this->path === null || $this->path === '') {
            return FileHelper::normalizePath(getcwd() . DIRECTORY_SEPARATOR . $filename);
        }

        $path = (string)Craft::getAlias($this->path);

        if (is_dir($path)) {
            $path .= DIRECTORY_SEPARATOR . $filename;
        }

        return FileHelper::normalizePath($path);
    }
}
