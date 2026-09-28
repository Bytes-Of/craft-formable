<?php

declare(strict_types=1);

namespace bytesof\formable\jobs;

use bytesof\formable\db\Table;
use bytesof\formable\elements\Submission;
use bytesof\formable\Plugin;
use Craft;
use craft\db\Query;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\queue\BaseJob;
use yii\db\Expression;

/**
 * Re-keys a form's stored answers and relation rows after a field's handle
 * changed, so a deliberate rename is lossless.
 *
 * Answers live in `formData` under the field's handle and the relations index
 * carries it in `fieldHandle`; neither has any idea the field kept its id.
 * Works on the tables directly, in id order, rather than resaving each
 * submission as an element: a resave would fire every save event, rewrite the
 * search index and stamp `dateUpdated` on data whose only change is a key.
 *
 * Batched, one batch per job execution. A batch commits together with the
 * push of the next one, so a job that dies mid-way is retried from a batch
 * boundary and never applies a batch twice - which matters because a swap
 * (`a`→`b`, `b`→`a`) is not idempotent the way a plain rename is.
 *
 * @internal
 */
final class RenameFieldHandles extends BaseJob
{
    private const BATCH_SIZE = 200;

    public int $formId = 0;

    /** @var array<string, string> `oldHandle => newHandle` */
    public array $renames = [];

    /** The last submission id the previous batch finished, exclusive. */
    public int $afterId = 0;

    public function execute($queue): void
    {
        if ($this->formId === 0 || $this->renames === []) {
            return;
        }

        $rows = (new Query())
            ->select(['id', 'formData'])
            ->from(Table::SUBMISSIONS)
            ->where(['formId' => $this->formId])
            ->andWhere(['>', 'id', $this->afterId])
            ->orderBy(['id' => SORT_ASC])
            ->limit(self::BATCH_SIZE)
            ->all();

        if ($rows === []) {
            Craft::$app->getElements()->invalidateCachesForElementType(Submission::class);

            return;
        }

        $layout = Plugin::getInstance()->getLayout();
        $ids = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            foreach ($rows as $row) {
                $data = Json::decodeIfJson($row['formData']);

                if (!is_array($data)) {
                    continue;
                }

                $renamed = $layout->renameKeys($data, $this->renames);

                if ($renamed !== $data) {
                    // The array, not encoded JSON - Craft's command layer encodes
                    // a json() column itself, and a string would be encoded twice.
                    Db::update(Table::SUBMISSIONS, ['formData' => $renamed], ['id' => $row['id']], [], false);
                }
            }

            $this->renameRelations($ids);

            if (count($rows) === self::BATCH_SIZE) {
                Craft::$app->getQueue()->push(new self([
                    'formId' => $this->formId,
                    'renames' => $this->renames,
                    'afterId' => end($ids),
                ]));
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        if (count($rows) < self::BATCH_SIZE) {
            Craft::$app->getElements()->invalidateCachesForElementType(Submission::class);
        }
    }

    /**
     * One `UPDATE` with a `CASE`, so a swap can't read a row it just wrote.
     *
     * @param int[] $submissionIds
     */
    private function renameRelations(array $submissionIds): void
    {
        $db = Craft::$app->getDb();
        $params = [];
        $cases = [];
        $index = 0;

        foreach ($this->renames as $old => $new) {
            $cases[] = "WHEN [[fieldHandle]] = :old$index THEN :new$index";
            $params[":old$index"] = $old;
            $params[":new$index"] = $new;
            $index++;
        }

        Db::update(
            Table::RELATIONS,
            ['fieldHandle' => new Expression('CASE ' . implode(' ', $cases) . ' ELSE [[fieldHandle]] END', $params)],
            ['and', ['submissionId' => $submissionIds], ['fieldHandle' => array_keys($this->renames)]],
            [],
            false,
            $db,
        );
    }

    /**
     * A generous reservation: a batch is a couple of hundred small row
     * updates, but a slow shared host should not see it reserved twice.
     */
    public function getTtr(): int
    {
        return 300;
    }

    protected function defaultDescription(): string
    {
        return Craft::t('formable', 'Moving a form’s stored answers to renamed fields');
    }
}
