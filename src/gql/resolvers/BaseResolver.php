<?php

declare(strict_types=1);

namespace bytesof\formable\gql\resolvers;

use craft\elements\db\ElementQuery;
use yii\base\UnknownMethodException;

/**
 * Shared plumbing for the Formable element resolvers.
 *
 * @internal
 */
abstract class BaseResolver
{
    /**
     * Applies a resolved argument set to an element query.
     *
     * The argument names are fixed by the query’s declared argument schema, so
     * each maps to a query method by name; an argument the query doesn’t know
     * is skipped rather than fatal, which keeps a future argument from breaking
     * an older query. A null value is left off entirely, so an omitted filter
     * doesn’t clobber a query default.
     *
     * @template TKey of array-key
     * @template TElement of \craft\base\ElementInterface
     * @param ElementQuery<TKey, TElement> $query
     * @param array<string, mixed> $arguments
     */
    protected static function applyArguments(ElementQuery $query, array $arguments): void
    {
        foreach ($arguments as $key => $value) {
            if ($value === null) {
                continue;
            }

            try {
                $query->{$key}($value);
            } catch (UnknownMethodException) {
                // A declared argument with no matching query method - ignore it.
            }
        }
    }
}
