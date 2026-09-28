<?php

declare(strict_types=1);

namespace bytesof\formable\elements\db;

use bytesof\formable\elements\Form;
use craft\db\QueryAbortedException;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;

/**
 * @template TKey of array-key
 * @template TElement of Form
 * @extends ElementQuery<TKey, TElement>
 *
 * @api
 */
final class FormQuery extends ElementQuery
{
    public mixed $handle = null;

    /**
     * Narrows the query results based on the forms’ handles.
     */
    public function handle(mixed $value): static
    {
        $this->handle = $value;

        return $this;
    }

    protected function beforePrepare(): bool
    {
        if ($this->handle === []) {
            throw new QueryAbortedException();
        }

        $this->joinElementTable('formable_forms');

        $this->query?->select([
            'formable_forms.handle',
            'formable_forms.pages',
            'formable_forms.settings',
            'formable_forms.translations',
            'formable_forms.defaultStatusId',
        ]);

        if ($this->handle !== null) {
            $this->subQuery?->andWhere(Db::parseParam('formable_forms.handle', $this->handle));
        }

        return parent::beforePrepare();
    }
}
