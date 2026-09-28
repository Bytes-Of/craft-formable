<?php

declare(strict_types=1);

namespace bytesof\formable\console\controllers;

use bytesof\formable\elements\Submission;
use bytesof\formable\Plugin;
use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use Throwable;
use yii\console\ExitCode;

/**
 * Maintenance tasks for stored submissions.
 *
 * `reindex` rebuilds the search index. Submissions saved before field values
 * became searchable only have their title indexed, so run it once after
 * upgrading to make the index search box find answers in every field:
 *
 *     php craft formable/submissions/reindex
 *     php craft formable/submissions/reindex --form=contact
 *
 * @internal
 */
final class SubmissionsController extends Controller
{
    public $defaultAction = 'reindex';

    /**
     * Limit the rebuild to one form, by handle. Defaults to every form.
     */
    public ?string $form = null;

    /**
     * @param string $actionID
     * @return array<int, string>
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'reindex') {
            $options[] = 'form';
        }

        return $options;
    }

    /**
     * Rebuilds the search index for stored submissions.
     */
    public function actionReindex(): int
    {
        $query = Submission::find()->status(null);

        if ($this->form !== null) {
            $form = Plugin::getInstance()->getForms()->getFormByHandle($this->form);

            if ($form === null) {
                $this->stderr(sprintf("No form exists with the handle \"%s\".\n", $this->form), Console::FG_RED);

                return ExitCode::UNSPECIFIED_ERROR;
            }

            $query->form($form);
        }

        // Cast: the query counts through the DB, which hands back a string on
        // some drivers - and a string never matches the comparisons below.
        $total = (int)$query->count();

        if ($total === 0) {
            $this->stdout("There are no submissions to reindex.\n", Console::FG_YELLOW);

            return ExitCode::OK;
        }

        Console::startProgress(0, $total);

        $search = Craft::$app->getSearch();
        $done = 0;
        $failed = 0;

        // Batched so a large submissions table doesn't have to fit in memory.
        foreach ($query->batch(100) as $submissions) {
            foreach ($submissions as $submission) {
                try {
                    $search->indexElementAttributes($submission);
                } catch (Throwable $e) {
                    $failed++;
                    Craft::error(sprintf(
                        'Could not reindex submission %d: %s',
                        $submission->id,
                        $e->getMessage()
                    ), __METHOD__);
                }

                Console::updateProgress(++$done, $total);
            }
        }

        Console::endProgress();

        if ($failed > 0) {
            $this->stderr(sprintf(
                "Reindexed %d of %d submissions - %d failed, see the logs.\n",
                $done - $failed,
                $total,
                $failed
            ), Console::FG_YELLOW);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(sprintf(
            "Reindexed %d %s.\n",
            $total,
            $total === 1 ? 'submission' : 'submissions'
        ), Console::FG_GREEN);

        return ExitCode::OK;
    }
}
