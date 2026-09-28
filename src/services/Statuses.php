<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\db\Table;
use bytesof\formable\models\Status;
use bytesof\formable\records\StatusRecord;
use Craft;
use craft\db\Query;
use craft\events\ConfigEvent;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use Throwable;
use yii\base\Component;

/**
 * Submission statuses: the workflow states a submission moves through.
 *
 * Statuses are install-level configuration rather than content, so project
 * config owns them - `formable.statuses.<uid>` is the definition, and the row
 * in `formable_statuses` is a read model kept in step by
 * {@see handleChangedStatus()} and {@see handleDeletedStatus()}. That is what
 * every write here goes through: nothing in this service writes the table
 * directly except those two handlers. See
 * internal/decisions/0023-submission-statuses-are-project-config.md.
 *
 * The table stays because two foreign keys point at it -
 * `formable_submissions.statusId` and `formable_forms.defaultStatusId` - and a
 * submission's status has to survive as a real reference, not a handle that may
 * or may not still resolve.
 *
 * @internal
 */
final class Statuses extends Component
{
    /**
     * The project config path statuses live under.
     */
    public const CONFIG_KEY = 'formable.statuses';

    /** @var array<int, Status>|null */
    private ?array $_statuses = null;

    /**
     * @return array<int, Status>
     */
    public function getAllStatuses(): array
    {
        if ($this->_statuses === null) {
            $this->_statuses = [];

            /** @var StatusRecord[] $records */
            $records = StatusRecord::find()
                ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
                ->all();

            foreach ($records as $record) {
                // Cast explicitly - MySQL drivers may return numeric strings,
                // which would fail the model's typed properties.
                $this->_statuses[] = new Status([
                    'id' => (int)$record->id,
                    'name' => (string)$record->name,
                    'handle' => (string)$record->handle,
                    'color' => (string)$record->color,
                    'sortOrder' => (int)$record->sortOrder,
                    'isDefault' => (bool)$record->isDefault,
                    'uid' => $record->uid,
                ]);
            }
        }

        return $this->_statuses;
    }

    public function getStatusById(int $id): ?Status
    {
        foreach ($this->getAllStatuses() as $status) {
            if ($status->id === $id) {
                return $status;
            }
        }

        return null;
    }

    public function getStatusByHandle(string $handle): ?Status
    {
        foreach ($this->getAllStatuses() as $status) {
            if ($status->handle === $handle) {
                return $status;
            }
        }

        return null;
    }

    public function getStatusByUid(string $uid): ?Status
    {
        foreach ($this->getAllStatuses() as $status) {
            if ($status->uid === $uid) {
                return $status;
            }
        }

        return null;
    }

    public function getDefaultStatus(): ?Status
    {
        foreach ($this->getAllStatuses() as $status) {
            if ($status->isDefault) {
                return $status;
            }
        }

        return $this->getAllStatuses()[0] ?? null;
    }

    /**
     * Saves a status to project config, creating it if it is new.
     *
     * Handle uniqueness is checked here rather than in the model's rules: the
     * model has to validate in a unit test with no database behind it, and a
     * duplicate handle is a fact about the other statuses, not about this one.
     */
    public function saveStatus(Status $status, bool $runValidation = true): bool
    {
        $isNew = $status->id === null;

        if ($runValidation) {
            if (!$status->validate()) {
                Craft::info('Status not saved due to validation error.', __METHOD__);

                return false;
            }

            $clash = $this->getStatusByHandle($status->handle);

            if ($clash !== null && $clash->id !== $status->id) {
                $status->addError('handle', Craft::t('formable', 'That handle is already in use.'));

                return false;
            }
        }

        if ($status->uid === null) {
            $status->uid = $isNew
                ? StringHelper::UUID()
                : (Db::uidById(Table::STATUSES, (int)$status->id) ?? StringHelper::UUID());
        }

        if ($isNew) {
            $status->sortOrder = (int)$this->query()->max('[[sortOrder]]') + 1;
        }

        // Which status currently holds the default is read from the table, not
        // from the memoized models: the caller is holding one of those and has
        // just changed it, so they no longer say what is stored.
        $stored = (int)$this->query()->count('[[id]]');
        $storedDefaultUid = $this->query()->select(['uid'])->andWhere(['isDefault' => true])->scalar() ?: null;

        // There is always exactly one default. A status can be promoted to it,
        // but never demoted into nothing: a form whose submissions arrived with
        // no status at all is the one state this workflow must not reach.
        if ($stored === 0 || $storedDefaultUid === $status->uid) {
            $status->isDefault = true;
        }

        $projectConfig = Craft::$app->getProjectConfig();

        if ($status->isDefault && $storedDefaultUid !== null && $storedDefaultUid !== $status->uid) {
            $demoted = $this->getStatusByUid((string)$storedDefaultUid);

            if ($demoted !== null) {
                $demoted = clone $demoted;
                $demoted->isDefault = false;

                $this->write($demoted, "Make the “{$status->handle}” submission status the default");
            }
        }

        $this->write($status, "Save the “{$status->handle}” submission status");

        if ($isNew) {
            $status->id = Db::idByUid(Table::STATUSES, $status->uid);
        }

        return true;
    }

    /**
     * Writes a new sort order, given status ids in the order they should appear.
     *
     * @param array<int, int> $ids
     */
    public function reorderStatuses(array $ids): bool
    {
        $reordered = [];

        foreach ($ids as $order => $id) {
            $status = $this->getStatusById($id);

            if ($status !== null) {
                $status = clone $status;
                $status->sortOrder = $order + 1;
                $reordered[] = $status;
            }
        }

        foreach ($reordered as $status) {
            $this->write($status, 'Reorder submission statuses');
        }

        return true;
    }

    public function deleteStatusById(int $id): bool
    {
        $status = $this->getStatusById($id);

        return $status !== null && $this->deleteStatus($status);
    }

    /**
     * Removes a status from project config.
     *
     * Two statuses cannot be deleted: the last one, and the default. Both leave
     * the install without a status to give an incoming submission, so the
     * refusal is recorded on the model for the caller to show.
     */
    public function deleteStatus(Status $status): bool
    {
        if (count($this->getAllStatuses()) < 2) {
            $status->addError('handle', Craft::t('formable', 'The only submission status can’t be deleted.'));

            return false;
        }

        if ($this->getDefaultStatus()?->id === $status->id) {
            $status->addError('isDefault', Craft::t('formable', 'The default submission status can’t be deleted. Make another status the default first.'));

            return false;
        }

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY . '.' . $status->uid,
            "Delete the “{$status->handle}” submission status",
        );

        return true;
    }

    /**
     * Applies an added or changed status to the read model.
     */
    public function handleChangedStatus(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        /** @var array<string, mixed> $data */
        $data = $event->newValue;
        $handle = (string)($data['handle'] ?? '');

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            $record = $this->statusRecord($uid, $handle);

            $record->name = (string)($data['name'] ?? '');
            $record->handle = $handle;
            $record->color = (string)($data['color'] ?? 'blue');
            $record->sortOrder = (int)($data['sortOrder'] ?? 0);
            $record->isDefault = (bool)($data['isDefault'] ?? false);
            $record->uid = $uid;
            $record->save(false);

            // Exactly one default, whatever arrived: a hand-edited config
            // carrying two would otherwise leave the read model ambiguous, and
            // whichever row was read first would decide.
            if ($record->isDefault) {
                StatusRecord::updateAll(['isDefault' => false], ['not', ['id' => $record->id]]);
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        $this->_statuses = null;
    }

    /**
     * Applies a removed status to the read model.
     *
     * Submissions that were sitting in it are moved to the default rather than
     * left with none: `statusId` is a `SET NULL` foreign key, and a submission
     * showing a blank where its status used to be reads as a bug.
     */
    public function handleDeletedStatus(ConfigEvent $event): void
    {
        $uid = $event->tokenMatches[0];
        $record = StatusRecord::findOne(['uid' => $uid]);

        if ($record === null) {
            return;
        }

        $id = (int)$record->id;
        $this->_statuses = null;
        $replacement = $this->replacementFor($id);

        $db = Craft::$app->getDb();
        $transaction = $db->beginTransaction();

        try {
            if ($replacement !== null) {
                // Not a timestamp-worthy edit: nobody touched these submissions,
                // the status under them was withdrawn.
                Db::update(Table::SUBMISSIONS, ['statusId' => $replacement->id], ['statusId' => $id], [], false);
            }

            // Hard delete. The record is soft-deletable, but a trashed status
            // that submissions still pointed at would be a status the control
            // panel cannot show and nothing can restore.
            StatusRecord::deleteAll(['id' => $id]);

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();

            throw $e;
        }

        $this->_statuses = null;
    }

    /**
     * Every status as project config would store it, for
     * `project-config/rebuild`.
     *
     * @return array<string, array<string, mixed>>
     */
    public function getConfigForRebuild(): array
    {
        $this->_statuses = null;
        $config = [];

        foreach ($this->getAllStatuses() as $status) {
            if ($status->uid !== null) {
                $config[$status->uid] = $status->getConfig();
            }
        }

        return $config;
    }

    /**
     * Writes a status to project config.
     *
     * Always the whole definition, never a single key beneath it. A nested path
     * like `…<uid>.sortOrder` only reaches the change handler by way of a
     * re-entrant commit of its parent, and that commit is skipped when the
     * parent has already been committed earlier in the same process - which is
     * exactly what an install migration, or any batch that saves and reorders
     * together, has just done. The config would move and the read model would
     * not follow.
     */
    private function write(Status $status, string $message): void
    {
        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY . '.' . $status->uid,
            $status->getConfig(),
            $message,
        );
    }

    /**
     * A query over the live status rows, for the counts and lookups that must
     * see the table rather than the memoized models.
     *
     * @return Query<int, array<string, mixed>>
     */
    private function query(): Query
    {
        return (new Query())
            ->from([Table::STATUSES])
            ->where(['dateDeleted' => null]);
    }

    /**
     * The record a config change applies to.
     *
     * Statuses were seeded per install before they moved into project config,
     * so the same four statuses carry a different uid in every environment. When
     * a uid is unknown but its handle is already on the table, that row is
     * adopted rather than a second one added beside it - which is what keeps an
     * updating site at four statuses instead of eight, and keeps every
     * submission pointing at the status it was given.
     */
    private function statusRecord(string $uid, string $handle): StatusRecord
    {
        $record = StatusRecord::findOne(['uid' => $uid]);

        if ($record !== null) {
            return $record;
        }

        if ($handle !== '') {
            $record = StatusRecord::findOne(['handle' => $handle]);

            if ($record !== null) {
                return $record;
            }
        }

        return new StatusRecord();
    }

    /**
     * The status submissions should be moved to when the one they are in is
     * deleted: the default, or failing that whichever is left.
     */
    private function replacementFor(int $id): ?Status
    {
        $replacement = null;

        foreach ($this->getAllStatuses() as $status) {
            if ($status->id === $id) {
                continue;
            }

            if ($replacement === null || $status->isDefault) {
                $replacement = $status;
            }
        }

        return $replacement;
    }
}
