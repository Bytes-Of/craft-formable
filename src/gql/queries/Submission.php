<?php

declare(strict_types=1);

namespace bytesof\formable\gql\queries;

use bytesof\formable\gql\arguments\SubmissionArguments;
use bytesof\formable\gql\resolvers\SubmissionResolver;
use bytesof\formable\gql\Scopes;
use bytesof\formable\gql\types\SubmissionType;
use GraphQL\Type\Definition\Type;

/**
 * Registers the top-level submission queries. Read scope on a form covers its
 * submissions too, so these ride the same gate as the form queries.
 *
 * @internal
 */
final class Submission
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getQueries(): array
    {
        if (!Scopes::canQueryForms()) {
            return [];
        }

        return [
            'formableSubmissions' => [
                'type' => Type::listOf(SubmissionType::getType()),
                'args' => SubmissionArguments::getArguments(),
                'resolve' => SubmissionResolver::class . '::resolve',
                'description' => 'Queries Formable submissions.',
            ],
            'formableSubmission' => [
                'type' => SubmissionType::getType(),
                'args' => SubmissionArguments::getArguments(),
                'resolve' => SubmissionResolver::class . '::resolveOne',
                'description' => 'Queries a single Formable submission.',
            ],
            'formableSubmissionCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => SubmissionArguments::getArguments(),
                'resolve' => SubmissionResolver::class . '::resolveCount',
                'description' => 'Returns the number of Formable submissions that match.',
            ],
        ];
    }
}
