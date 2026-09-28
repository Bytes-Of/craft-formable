<?php

declare(strict_types=1);

namespace bytesof\formable\gql\arguments;

use craft\gql\types\QueryArgument;
use GraphQL\Type\Definition\Type;

/**
 * Arguments for the submission queries.
 *
 * @internal
 */
final class SubmissionArguments
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getArguments(): array
    {
        return [
            'id' => [
                'name' => 'id',
                'type' => Type::listOf(QueryArgument::getType()),
                'description' => 'Narrows the results based on the submissions’ IDs.',
            ],
            'uid' => [
                'name' => 'uid',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results based on the submissions’ UIDs.',
            ],
            'form' => [
                'name' => 'form',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results to submissions of the forms with these handles.',
            ],
            'formId' => [
                'name' => 'formId',
                'type' => Type::listOf(QueryArgument::getType()),
                'description' => 'Narrows the results to submissions of the forms with these IDs.',
            ],
            'status' => [
                'name' => 'status',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results based on the submissions’ statuses.',
            ],
            'isSpam' => [
                'name' => 'isSpam',
                'type' => Type::boolean(),
                'description' => 'Whether to return spam submissions. Defaults to false.',
            ],
            'isIncomplete' => [
                'name' => 'isIncomplete',
                'type' => Type::boolean(),
                'description' => 'Whether to return part-filled, resumable submissions. Defaults to false.',
            ],
            'userId' => [
                'name' => 'userId',
                'type' => Type::listOf(QueryArgument::getType()),
                'description' => 'Narrows the results to submissions made by the users with these IDs.',
            ],
            'submittedSite' => [
                'name' => 'submittedSite',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results to submissions made from the sites with these handles.',
            ],
            'limit' => [
                'name' => 'limit',
                'type' => Type::int(),
                'description' => 'The number of submissions to return.',
            ],
            'offset' => [
                'name' => 'offset',
                'type' => Type::int(),
                'description' => 'How many submissions to skip.',
            ],
            'orderBy' => [
                'name' => 'orderBy',
                'type' => Type::string(),
                'description' => 'The field the results should be ordered by.',
            ],
        ];
    }
}
