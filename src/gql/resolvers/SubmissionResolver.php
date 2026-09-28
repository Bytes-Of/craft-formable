<?php

declare(strict_types=1);

namespace bytesof\formable\gql\resolvers;

use bytesof\formable\elements\db\SubmissionQuery;
use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\gql\Scopes;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * Resolves the `formableSubmissions` / `formableSubmission` queries.
 *
 * A submission is reachable only through a form the active schema has read
 * scope for: the query is bounded to those forms’ IDs, ANDed on after the
 * caller’s arguments so a `form`/`formId` argument can only ever narrow within
 * the granted set, never past it.
 *
 * @internal
 */
final class SubmissionResolver extends BaseResolver
{
    /**
     * @param array<string, mixed> $arguments
     * @return array<int, Submission>
     */
    public static function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): array
    {
        $query = self::query($arguments);

        return $query !== null ? $query->all() : [];
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function resolveOne(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): ?Submission
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
     * @return SubmissionQuery<int, Submission>|null
     */
    private static function query(array $arguments): ?SubmissionQuery
    {
        $allowedUids = Scopes::allowedFormUids(Scopes::ACTION_READ);

        if ($allowedUids === null) {
            return null;
        }

        $allowedFormIds = Form::find()
            ->uid($allowedUids)
            ->status(null)
            ->ids();

        $query = Submission::find();
        self::applyArguments($query, $arguments);
        $query->andWhere(['formable_submissions.formId' => $allowedFormIds]);

        return $query;
    }
}
