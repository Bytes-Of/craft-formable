<?php

declare(strict_types=1);

namespace bytesof\formable\console\controllers;

use bytesof\formable\db\Table;
use bytesof\formable\elements\exporters\SubmissionExport;
use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\fields\Checkboxes;
use bytesof\formable\fields\Dropdown;
use bytesof\formable\fields\Email;
use bytesof\formable\fields\Entries;
use bytesof\formable\fields\Text;
use bytesof\formable\fields\Textarea;
use bytesof\formable\Plugin;
use Craft;
use craft\console\Controller;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\db\ElementQueryInterface;
use craft\elements\Entry;
use craft\helpers\Console;
use craft\helpers\Db;
use craft\helpers\StringHelper;
use DateInterval;
use DateTime;
use yii\base\Action;
use yii\console\ExitCode;

/**
 * Seeds a large submissions table and times the queries that have to survive
 * it - the evidence behind the submission indexes
 * ([[0112-submission-indexes-follow-the-measured-query-shapes]]).
 *
 *     php craft formable/benchmark/seed --count=1000000
 *     php craft formable/benchmark/run
 *     php craft formable/benchmark/run --explain
 *     php craft formable/benchmark/clear
 *
 * Seeding writes straight to the tables rather than saving elements: a million
 * element saves is hours, and what is being measured is reading the rows, not
 * writing them. The rows are shaped like real ones - dates spread over two
 * years, a few percent spam and in-progress, one form holding most of the
 * volume - because an index that only helps a uniform table helps nobody.
 *
 * devMode only. It writes a million fake submissions; nothing about that
 * belongs anywhere near a production database.
 *
 * @internal
 */
final class BenchmarkController extends Controller
{
    private const HANDLE_PREFIX = 'formableBenchmark';
    private const CHUNK = 1000;

    private const NAMES = [
        'Maria', 'Ana', 'Jonas', 'Aisha', 'Kofi', 'Mei', 'Lars', 'Priya', 'Diego', 'Fatima',
        'Olu', 'Ingrid', 'Tomas', 'Yuki', 'Sven', 'Amara', 'Noor', 'Luca', 'Elena', 'Ravi',
    ];

    public int $count = 1_000_000;

    /**
     * How many benchmark forms to spread the submissions across. The first
     * takes 60% of them, so one form's source is the big one.
     */
    public int $forms = 5;

    public int $runs = 5;

    public bool $explain = false;

    /**
     * How many submissions the export case hydrates. The whole table does not
     * fit in memory, which is the finding; this is enough to measure the rate.
     */
    public int $exportRows = 20_000;

    /**
     * @param string $actionID
     * @return array<int, string>
     */
    public function options($actionID): array
    {
        return match ($actionID) {
            'seed' => [...parent::options($actionID), 'count', 'forms'],
            'run' => [...parent::options($actionID), 'runs', 'explain', 'exportRows'],
            default => parent::options($actionID),
        };
    }

    /**
     * @param Action<self> $action
     */
    public function beforeAction($action): bool
    {
        if (!Craft::$app->getConfig()->getGeneral()->devMode) {
            $this->stderr("The benchmark only runs with devMode on.\n", Console::FG_RED);

            return false;
        }

        return parent::beforeAction($action);
    }

    /**
     * Inserts `--count` submissions across `--forms` benchmark forms.
     */
    public function actionSeed(): int
    {
        $forms = $this->benchmarkForms(true);
        $formIds = array_map(static fn(Form $form): int => (int)$form->id, $forms);
        $statusIds = array_map(
            static fn($status): int => (int)$status->id,
            Plugin::getInstance()->getStatuses()->getAllStatuses(),
        );
        $siteId = (int)Craft::$app->getSites()->getPrimarySite()->id;
        $entryIds = Entry::find()->status(null)->limit(50)->ids();
        $db = Craft::$app->getDb();
        $seedSearch = $db->getIsMysql();

        if (!$seedSearch) {
            $this->stdout("Not seeding the search index: Postgres needs its tsvector column filled too.\n", Console::FG_YELLOW);
        }

        $now = new DateTime();
        $twoYears = 2 * 365 * 86400;

        Console::startProgress(0, $this->count);

        for ($done = 0; $done < $this->count; $done += self::CHUNK) {
            $size = min(self::CHUNK, $this->count - $done);
            $rows = [];

            for ($i = 0; $i < $size; $i++) {
                $created = (clone $now)->sub(new DateInterval('PT' . random_int(0, $twoYears) . 'S'));
                $roll = random_int(1, 100);
                $rows[] = [
                    'uid' => StringHelper::UUID(),
                    'date' => Db::prepareDateForDb($created),
                    'formId' => $this->pickForm($formIds),
                    'statusId' => $statusIds === [] ? null : $statusIds[array_rand($statusIds)],
                    'isSpam' => $roll <= 3,
                    'isIncomplete' => $roll > 3 && $roll <= 5,
                    'name' => self::NAMES[array_rand(self::NAMES)] . ' ' . self::NAMES[array_rand(self::NAMES)],
                    'number' => $done + $i,
                ];
            }

            $this->insertChunk($rows, $siteId, $entryIds, $seedSearch, $now);
            Console::updateProgress($done + $size, $this->count);
        }

        Console::endProgress();
        $this->stdout(sprintf("Seeded %d submissions.\n", $this->count), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * Times the submission queries the control panel, the widgets and the
     * scheduled jobs actually run, each `--runs` times, reporting the median.
     */
    public function actionRun(): int
    {
        $forms = $this->benchmarkForms(false);

        if ($forms === []) {
            $this->stderr("Nothing to benchmark - run formable/benchmark/seed first.\n", Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $big = (int)$forms[0]->id;
        $small = (int)$forms[array_key_last($forms)]->id;
        $status = Plugin::getInstance()->getStatuses()->getAllStatuses()[0] ?? null;
        $relatedId = (new Query())->select(['elementId'])->from(Table::RELATIONS)->scalar();
        $weekAgo = Db::prepareDateForDb((new DateTime())->sub(new DateInterval('P7D')));
        $yearAgo = Db::prepareDateForDb((new DateTime())->sub(new DateInterval('P365D')));
        $total = (new Query())->from(Table::SUBMISSIONS)->count();

        $this->stdout(sprintf("%d submission rows, %s.\n\n", $total, Craft::$app->getDb()->getDriverLabel()));

        $page = static fn(ElementQueryInterface $query): ElementQueryInterface => $query
            ->orderBy(['formable_submissions.dateCreated' => SORT_DESC])
            ->limit(50);

        $cases = [
            'index: all, page 1' => $page(Submission::find()),
            'index: all, count' => ['count', Submission::find()],
            'index: big form, page 1' => $page(Submission::find()->formId($big)),
            'index: big form, count' => ['count', Submission::find()->formId($big)],
            'index: small form, page 1' => $page(Submission::find()->formId($small)),
            'index: big form, page 200' => $page(Submission::find()->formId($big))->offset(199 * 50),
            'index: spam, page 1' => $page(Submission::find()->isSpam(true)),
            'index: spam, count' => ['count', Submission::find()->isSpam(true)],
            'index: big form by status, page 1' => $page(Submission::find()->formId($big)->status($status?->handle)),
            'index: sort by date updated' => Submission::find()->formId($big)->orderBy(['elements.dateUpdated' => SORT_DESC])->limit(50),
            'index: search "maria"' => $page(Submission::find()->formId($big)->search('maria')),
            'related to an entry' => $page(Submission::find()->relatedToElementId($relatedId === false ? 0 : $relatedId)),
            'submission limit (cap 1000)' => ['exists', Submission::find()->formId($big)->isIncomplete(false)->isSpam(false)->status(null)->orderBy(null)->offset(999)],
            'digest: form, last 7 days' => ['count', Submission::find()->formId($big)->isIncomplete(false)->isSpam(false)->status(null)
                ->andWhere(['>=', 'formable_submissions.dateCreated', $weekAgo])],
            'purge: expired incomplete, one batch' => ['all', Submission::find()->limit(100)->isIncomplete(true)->status(null)
                ->andWhere(['not', ['formable_submissions.dateExpired' => null]])
                ->andWhere(['<', 'formable_submissions.dateExpired', Db::prepareDateForDb(new DateTime())])],
            'retention: older than a year, one batch' => ['all', Submission::find()->limit(100)->formId($big)->isIncomplete(false)->status(null)
                ->andWhere(['<', 'formable_submissions.dateCreated', $yearAgo])],
        ];

        foreach ($cases as $label => $case) {
            [$method, $query] = is_array($case) ? $case : ['all', $case];
            $this->report($label, fn() => $query->$method(), $query);
        }

        $this->reportExport($big);

        return ExitCode::OK;
    }

    /**
     * Deletes every benchmark submission and form.
     */
    public function actionClear(): int
    {
        $forms = $this->benchmarkForms(false);
        $formIds = array_map(static fn(Form $form): int => (int)$form->id, $forms);
        $deleted = 0;

        // Chunked by id: one DELETE over a million rows holds its locks for
        // minutes. Deleting the element row cascades to the submission, its
        // elements_sites row and its relations.
        do {
            $ids = (new Query())
                ->select(['id'])
                ->from(Table::SUBMISSIONS)
                ->where(['formId' => $formIds])
                ->limit(5000)
                ->column();

            if ($ids !== []) {
                Db::delete(CraftTable::SEARCHINDEX, ['elementId' => $ids]);
                $deleted += Db::delete(CraftTable::ELEMENTS, ['id' => $ids]);
            }
        } while ($ids !== [] && $formIds !== []);

        foreach ($forms as $form) {
            Craft::$app->getElements()->deleteElement($form, true);
        }

        $this->stdout(sprintf("Deleted %d submissions and %d forms.\n", $deleted, count($forms)), Console::FG_GREEN);

        return ExitCode::OK;
    }

    /**
     * @return array<int, Form>
     */
    private function benchmarkForms(bool $create): array
    {
        $forms = [];

        for ($i = 1; $i <= ($create ? $this->forms : 50); $i++) {
            $handle = self::HANDLE_PREFIX . $i;
            $form = Plugin::getInstance()->getForms()->getFormByHandle($handle);

            if ($form === null && $create) {
                $form = $this->createForm($handle, $i);
            }

            if ($form === null) {
                break;
            }

            $forms[] = $form;
        }

        return $forms;
    }

    private function createForm(string $handle, int $number): Form
    {
        $options = static fn(string ...$labels): array => array_map(
            static fn(string $label): array => ['label' => $label, 'value' => strtolower($label)],
            $labels,
        );

        $fields = [
            ['type' => Text::class, 'handle' => 'name', 'label' => 'Name'],
            ['type' => Email::class, 'handle' => 'email', 'label' => 'Email'],
            ['type' => Textarea::class, 'handle' => 'message', 'label' => 'Message'],
            ['type' => Dropdown::class, 'handle' => 'country', 'label' => 'Country', 'options' => $options('Kenya', 'Norway', 'Peru')],
            ['type' => Checkboxes::class, 'handle' => 'interests', 'label' => 'Interests', 'options' => $options('News', 'Events', 'Offers')],
            ['type' => Entries::class, 'handle' => 'relatedPages', 'label' => 'Related pages'],
        ];

        $form = new Form();
        $form->title = "Benchmark $number";
        $form->handle = $handle;
        $form->setPages([[
            'id' => 'page-0',
            'label' => 'Page 1',
            'settings' => [],
            'rows' => array_map(
                static fn(array $field, int $i): array => ['id' => "row-$i", 'fields' => [['id' => "field-$i"] + $field]],
                $fields,
                array_keys($fields),
            ),
        ]]);

        if (!Plugin::getInstance()->getForms()->saveForm($form)) {
            throw new \RuntimeException("Could not save benchmark form $handle: " . implode(', ', $form->getFirstErrors()));
        }

        return $form;
    }

    /**
     * @param array<int, int> $formIds
     */
    private function pickForm(array $formIds): int
    {
        if (count($formIds) === 1 || random_int(1, 100) <= 60) {
            return $formIds[0];
        }

        return $formIds[random_int(1, count($formIds) - 1)];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, int> $entryIds
     */
    private function insertChunk(array $rows, int $siteId, array $entryIds, bool $seedSearch, DateTime $now): void
    {
        $db = Craft::$app->getDb();

        Db::batchInsert(
            CraftTable::ELEMENTS,
            ['type', 'enabled', 'archived', 'dateCreated', 'dateUpdated', 'uid'],
            array_map(static fn(array $r): array => [Submission::class, true, false, $r['date'], $r['date'], $r['uid']], $rows),
        );

        // A multi-row insert hands back no ids, so read them back by uid.
        $ids = (new Query())
            ->select(['id', 'uid'])
            ->from(CraftTable::ELEMENTS)
            ->where(['uid' => array_column($rows, 'uid')])
            ->pairs();
        $ids = array_flip($ids);

        $sites = [];
        $submissions = [];
        $relations = [];
        $search = [];
        $expires = Db::prepareDateForDb((clone $now)->add(new DateInterval('P30D')));
        $expired = Db::prepareDateForDb((clone $now)->sub(new DateInterval('P1D')));

        foreach ($rows as $r) {
            $id = (int)$ids[$r['uid']];
            $email = strtolower(str_replace(' ', '.', $r['name'])) . "{$r['number']}@example.com";
            $title = "{$r['name']} ({$email})";
            $related = $entryIds !== [] && random_int(1, 10) === 1 ? $entryIds[array_rand($entryIds)] : null;

            $sites[] = [$id, $siteId, $title, true, $r['date'], $r['date'], StringHelper::UUID()];
            $submissions[] = [
                $id,
                $r['formId'],
                $r['statusId'],
                Db::prepareValueForDb([
                    'name' => $r['name'],
                    'email' => $email,
                    'message' => str_repeat('Lorem ipsum dolor sit amet. ', random_int(1, 12)),
                    'country' => ['kenya', 'norway', 'peru'][random_int(0, 2)],
                    'interests' => ['news'],
                    'relatedPages' => $related === null ? [] : [$related],
                ]),
                $siteId,
                '203.0.113.' . random_int(1, 254),
                'Mozilla/5.0 (Benchmark)',
                $r['isIncomplete'],
                $r['isSpam'],
                $r['isSpam'] ? 'honeypot' : null,
                $r['isIncomplete'] ? StringHelper::randomString(32) : null,
                0,
                $r['isIncomplete'] ? (random_int(0, 1) === 1 ? $expires : $expired) : null,
                $r['date'],
                $r['date'],
                $r['uid'],
            ];

            if ($related !== null) {
                $relations[] = [$id, $related, 'relatedPages', 0, $r['date'], $r['date'], StringHelper::UUID()];
            }

            if ($seedSearch) {
                $search[] = [$id, 'title', 0, $siteId, ' ' . strtolower(preg_replace('/[^a-z0-9]+/i', ' ', $title) ?? '') . ' '];
            }
        }

        Db::batchInsert(CraftTable::ELEMENTS_SITES, ['elementId', 'siteId', 'title', 'enabled', 'dateCreated', 'dateUpdated', 'uid'], $sites);
        Db::batchInsert(Table::SUBMISSIONS, [
            'id', 'formId', 'statusId', 'formData', 'submittedSiteId', 'ipAddress', 'userAgent', 'isIncomplete',
            'isSpam', 'spamReason', 'resumeToken', 'pageIndex', 'dateExpired', 'dateCreated', 'dateUpdated', 'uid',
        ], $submissions);
        Db::batchInsert(Table::RELATIONS, ['submissionId', 'elementId', 'fieldHandle', 'sortOrder', 'dateCreated', 'dateUpdated', 'uid'], $relations);

        if ($search !== []) {
            $db->createCommand()->batchInsert(CraftTable::SEARCHINDEX, ['elementId', 'attribute', 'fieldId', 'siteId', 'keywords'], $search)->execute();
        }
    }

    private function report(string $label, callable $run, ElementQueryInterface $query): void
    {
        $times = [];

        for ($i = 0; $i < max(1, $this->runs); $i++) {
            $start = hrtime(true);
            $run();
            $times[] = (hrtime(true) - $start) / 1e6;
        }

        sort($times);
        $median = $times[intdiv(count($times), 2)];
        $colour = $median < 100 ? Console::FG_GREEN : ($median < 500 ? Console::FG_YELLOW : Console::FG_RED);

        $this->stdout(sprintf('%-42s', $label));
        $this->stdout(sprintf("%9.1f ms\n", $median), $colour);

        if ($this->explain) {
            $this->explainQuery($query);
        }
    }

    private function explainQuery(ElementQueryInterface $query): void
    {
        $sql = $query->createCommand()->getRawSql();

        foreach (Craft::$app->getDb()->createCommand("EXPLAIN $sql")->queryAll() as $row) {
            $this->stdout('    ' . implode(' | ', array_map(static fn($v): string => (string)$v, $row)) . "\n", Console::FG_GREY);
        }
    }

    /**
     * The export can't be timed at full size - it holds every hydrated
     * submission at once, which is the problem - so this measures the per-row
     * cost and projects it onto the form's real count.
     */
    private function reportExport(int $formId): void
    {
        $count = (int)Submission::find()->formId($formId)->count();
        $query = Submission::find()->formId($formId)->limit($this->exportRows);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $before = memory_get_usage();
        $start = hrtime(true);

        $rows = (new SubmissionExport())->export($query);

        $seconds = (hrtime(true) - $start) / 1e9;
        $bytes = memory_get_peak_usage() - $before;
        $exported = count($rows);
        $perRow = $exported > 0 ? $bytes / $exported : 0;

        $this->stdout(sprintf(
            "\nexport: %d rows in %.1f s, %.0f MB peak (%.1f KB/row)\n",
            $exported,
            $seconds,
            $bytes / 1048576,
            $perRow / 1024,
        ));
        $this->stdout(sprintf(
            "export: projected for the whole form (%d rows): %.0f s, %.1f GB before Craft's spreadsheet writer\n",
            $count,
            $exported > 0 ? $seconds / $exported * $count : 0,
            $perRow * $count / 1073741824,
        ), Console::FG_YELLOW);
    }
}
