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
 * Within that set, a query answers with completed, non-spam submissions unless
 * it says otherwise - see {@see applyDocumentedDefaults()}, which is where that
 * default is actually applied.
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
        self::applyDocumentedDefaults($query, $arguments);
        $query->andWhere(['formable_submissions.formId' => $allowedFormIds]);

        return $query;
    }

    /**
     * Narrows to completed, non-spam submissions unless the caller said
     * otherwise - the default the two arguments document.
     *
     * `SubmissionQuery`'s own defaults stand aside for a query that names ids
     * or uids ([[0129-a-lookup-by-id-sees-every-submission]]), so the narrowing
     * has to be asked for here. It cannot be left to the argument declarations:
     * graphql-php materialises an omitted argument only where the definition
     * carries a `defaultValue`, and an absent argument never reaches
     * {@see applyArguments()}. Without this, `formableSubmission(id: ...)` on
     * any schema with submission read scope answered with spam and part-filled
     * drafts, answers and all.
     *
     * A null is read as the default rather than as a request for both, which is
     * what an omitted argument and an explicit `isSpam: null` both did before
     * 0129 - there is no documented way to ask for both at once.
     *
     * [[0131-graphql-submission-defaults-are-named-by-the-resolver]]
     *
     * @param SubmissionQuery<int, Submission> $query
     * @param array<string, mixed> $arguments
     */
    private static function applyDocumentedDefaults(SubmissionQuery $query, array $arguments): void
    {
        if (($arguments['isSpam'] ?? null) === null) {
            $query->isSpam(false);
        }

        if (($arguments['isIncomplete'] ?? null) === null) {
            $query->isIncomplete(false);
        }
    }
}
