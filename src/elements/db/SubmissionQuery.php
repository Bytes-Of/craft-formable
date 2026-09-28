<?php

declare(strict_types=1);

namespace bytesof\formable\elements\db;

use bytesof\formable\db\Table;
use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\Plugin;
use Craft;
use craft\base\Element;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\QueryAbortedException;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use craft\models\Site;

/**
 * @template TKey of array-key
 * @template TElement of Submission
 * @extends ElementQuery<TKey, TElement>
 *
 * @api
 */
final class SubmissionQuery extends ElementQuery
{
    public mixed $formId = null;
    public mixed $statusId = null;
    public mixed $userId = null;
    public mixed $submittedSiteId = null;
    public ?bool $isIncomplete = false;
    public ?bool $isSpam = false;
    /** @internal */
    public ?string $resumeToken = null;
    public mixed $relatedToElementId = null;

    /**
     * Newest first by the submission row's date rather than the element's, so
     * `craft.formable.submissions` and GraphQL get the indexed sort too
     * ([[0112-submission-indexes-follow-the-measured-query-shapes]]).
     *
     * @var array<string, int>
     */
    protected array $defaultOrderBy = [
        'formable_submissions.dateCreated' => SORT_DESC,
        'formable_submissions.id' => SORT_DESC,
    ];

    /**
     * Narrows the query results based on the form the submissions belong to.
     *
     * Accepts a form, a single handle, or a list of handles - the last is what
     * the GraphQL `form` argument passes.
     *
     * @param Form|string|array<int, string>|null $value
     */
    public function form(Form|string|array|null $value): static
    {
        if ($value instanceof Form) {
            $this->formId = $value->id;
        } elseif ($value !== null) {
            $this->formId = Form::find()->handle($value)->ids();
        } else {
            $this->formId = null;
        }

        return $this;
    }

    public function formId(mixed $value): static
    {
        $this->formId = $value;

        return $this;
    }

    /**
     * Narrows the results by submission status.
     */
    public function statusId(mixed $value): static
    {
        $this->statusId = $value;

        return $this;
    }

    /**
     * Narrows the results to submissions made by a given user - what
     * `craft.formable.submissions` scopes a member to their own.
     */
    public function userId(mixed $value): static
    {
        $this->userId = $value;

        return $this;
    }

    /**
     * Narrows the results to submissions made from given sites.
     *
     * Deliberately not `siteId()`, which is Craft's own and means "load these
     * elements for this site" - a submission is single-site, so that one is
     * always the primary site and would silently return everything.
     *
     * @param Site|string|array<int, Site|string|int>|int|null $value
     */
    public function submittedSite(Site|string|array|int|null $value): static
    {
        if ($value === null) {
            $this->submittedSiteId = null;

            return $this;
        }

        $ids = [];

        foreach (is_array($value) ? $value : [$value] as $site) {
            $id = match (true) {
                $site instanceof Site => $site->id,
                is_int($site) => $site,
                default => Craft::$app->getSites()->getSiteByHandle((string)$site)?->id,
            };

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        // An empty list, not null: a handle that matches no site has to return
        // nothing, the way `form()` does - falling back to "every site" would
        // answer a typo with the whole table.
        $this->submittedSiteId = $ids;

        return $this;
    }

    public function submittedSiteId(mixed $value): static
    {
        $this->submittedSiteId = $value;

        return $this;
    }

    /**
     * Pass null to include both complete and incomplete submissions.
     */
    public function isIncomplete(?bool $value): static
    {
        $this->isIncomplete = $value;

        return $this;
    }

    /**
     * Pass null to include both spam and legitimate submissions.
     */
    public function isSpam(?bool $value): static
    {
        $this->isSpam = $value;

        return $this;
    }

    /**
     * Narrows the results to the submission holding this save-and-resume token.
     *
     * @internal
     */
    public function resumeToken(?string $value): static
    {
        $this->resumeToken = $value;

        return $this;
    }

    /**
     * Narrows the results to submissions whose answers point at given elements -
     * an entry or category someone selected, or an asset they uploaded.
     *
     * Deliberately not Craft's own `relatedTo()`, which reads the core
     * `relations` table: Formable's fields are not Craft fields, have no field
     * id, and so cannot be recorded there. This reads the plugin's own index -
     * see internal/decisions/0022-submission-relations-are-indexed-on-save.md.
     *
     * @param ElementInterface|array<int, ElementInterface|int>|int|null $value
     */
    public function relatedToElement(ElementInterface|array|int|null $value): static
    {
        if ($value === null) {
            $this->relatedToElementId = null;

            return $this;
        }

        $ids = [];

        foreach (is_array($value) ? $value : [$value] as $element) {
            $id = $element instanceof ElementInterface ? $element->id : $element;

            if ($id !== null) {
                $ids[] = (int)$id;
            }
        }

        // An empty list, not null: an unsaved element relates to nothing, and
        // answering that with every submission would be worse than useless.
        $this->relatedToElementId = $ids;

        return $this;
    }

    public function relatedToElementId(mixed $value): static
    {
        $this->relatedToElementId = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        if ($this->formId === [] || $this->submittedSiteId === [] || $this->relatedToElementId === []) {
            throw new QueryAbortedException();
        }

        $this->joinElementTable('formable_submissions');

        $this->query?->select([
            'formable_submissions.formId',
            'formable_submissions.statusId',
            'formable_submissions.userId',
            'formable_submissions.formData',
            'formable_submissions.submittedSiteId',
            'formable_submissions.ipAddress',
            'formable_submissions.userAgent',
            'formable_submissions.isIncomplete',
            'formable_submissions.isSpam',
            'formable_submissions.spamReason',
            'formable_submissions.resumeToken',
            'formable_submissions.pageIndex',
            'formable_submissions.dateExpired',
        ]);

        if ($this->formId !== null) {
            $this->subQuery?->andWhere(Db::parseNumericParam('formable_submissions.formId', $this->formId));
        }

        if ($this->statusId !== null) {
            $this->subQuery?->andWhere(Db::parseNumericParam('formable_submissions.statusId', $this->statusId));
        }

        if ($this->userId !== null) {
            $this->subQuery?->andWhere(Db::parseNumericParam('formable_submissions.userId', $this->userId));
        }

        if ($this->submittedSiteId !== null) {
            $this->subQuery?->andWhere(Db::parseNumericParam('formable_submissions.submittedSiteId', $this->submittedSiteId));
        }

        if ($this->isIncomplete !== null) {
            $this->subQuery?->andWhere(['formable_submissions.isIncomplete' => $this->isIncomplete]);
        }

        if ($this->isSpam !== null) {
            $this->subQuery?->andWhere(['formable_submissions.isSpam' => $this->isSpam]);
        }

        if ($this->resumeToken !== null) {
            // An empty token would otherwise match every completed submission,
            // whose column is null - a resume link with a blank token would
            // hand back somebody else's answers.
            if ($this->resumeToken === '') {
                throw new QueryAbortedException();
            }

            $this->subQuery?->andWhere(['formable_submissions.resumeToken' => $this->resumeToken]);
        }

        if ($this->relatedToElementId !== null) {
            // A subquery rather than a join: a submission that points at the
            // same element from two fields has two rows in the index, and a
            // join would hand it back twice.
            $this->subQuery?->andWhere([
                'formable_submissions.id' => (new Query())
                    ->select(['submissionId'])
                    ->from([Table::RELATIONS])
                    ->where(Db::parseNumericParam('elementId', $this->relatedToElementId)),
            ]);
        }

        return parent::beforePrepare();
    }

    /**
     * Here rather than in `beforePrepare()`: Craft selects the subquery's
     * columns after that runs, and `select()` resets the select option.
     */
    protected function afterPrepare(): bool
    {
        if ($this->subQuery !== null && $this->readsDateIndexInOrder($this->subQuery->orderBy)) {
            // MySQL costs the join ignoring the LIMIT, so for a form holding
            // most of the table it drives from `elements` and sorts every row
            // of the form to return fifty. Starting from this table walks the
            // (formId, isSpam, isIncomplete, dateCreated) index in order and
            // stops at the page ([[0112-submission-indexes-follow-the-measured-query-shapes]]).
            // A comment to MariaDB and Postgres.
            $this->subQuery->selectOption = '/*+ JOIN_PREFIX(formable_submissions) */';
        }

        return parent::afterPrepare();
    }

    /**
     * Whether this is a newest-first listing narrowed only by form, site,
     * spam and progress - the one shape the date index answers by reading a
     * page and stopping.
     *
     * Anything that picks out a few rows some other way (ids, a search, a
     * member's own submissions, a status, a related element) is left to the
     * optimizer, which starts from that narrower side on its own and would be
     * slowed by being made to walk a whole form's dates.
     */
    private function readsDateIndexInOrder(mixed $orderBy): bool
    {
        if (!is_array($orderBy) || array_key_first($orderBy) !== 'formable_submissions.dateCreated') {
            return false;
        }

        $status = $this->status === null ? [] : (array)$this->status;

        return $this->id === null
            && $this->uid === null
            && $this->search === null
            && $this->relatedTo === null
            && $this->userId === null
            && $this->statusId === null
            && $this->resumeToken === null
            && $this->relatedToElementId === null
            && array_diff($status, [Element::STATUS_ENABLED]) === [];
    }

    /**
     * Maps a status handle from the index's status menu onto our own statusId,
     * so "Approved" filters the column rather than falling through to Craft's
     * enabled/disabled handling.
     *
     * @return mixed
     */
    protected function statusCondition(string $status): mixed
    {
        $model = Plugin::getInstance()->getStatuses()->getStatusByHandle($status);

        if ($model !== null) {
            return ['formable_submissions.statusId' => $model->id];
        }

        return parent::statusCondition($status);
    }
}
