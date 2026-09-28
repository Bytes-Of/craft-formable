<?php

declare(strict_types=1);

namespace bytesof\formable\gql\resolvers;

use bytesof\formable\elements\db\FormQuery;
use bytesof\formable\elements\Form;
use bytesof\formable\gql\Scopes;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * Resolves the `formableForms` / `formableForm` queries.
 *
 * Every query is bounded to the forms the active schema was granted read scope
 * for - the scope filter is ANDed onto the query after the caller’s arguments,
 * so no argument can widen a query past its schema’s reach.
 *
 * @internal
 */
final class FormResolver extends BaseResolver
{
    /**
     * @param array<string, mixed> $arguments
     * @return array<int, Form>
     */
    public static function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): array
    {
        $query = self::query($arguments);

        return $query !== null ? $query->all() : [];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function resolveOne(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): ?Form
    {
        return self::query($arguments)?->one();
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function resolveCount(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): int
    {
        return (int)(self::query($arguments)?->count() ?? 0);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return FormQuery<int, Form>|null
     */
    private static function query(array $arguments): ?FormQuery
    {
        $allowedUids = Scopes::allowedFormUids(Scopes::ACTION_READ);

        if ($allowedUids === null) {
            return null;
        }

        $query = Form::find();
        self::applyArguments($query, $arguments);
        $query->andWhere(['elements.uid' => $allowedUids]);

        return $query;
    }
}
