<?php

declare(strict_types=1);

namespace bytesof\formable\gql\arguments;

use craft\gql\types\QueryArgument;
use GraphQL\Type\Definition\Type;

/**
 * Arguments for the form queries.
 *
 * @internal
 */
final class FormArguments
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
                'description' => 'Narrows the results based on the forms’ IDs.',
            ],
            'uid' => [
                'name' => 'uid',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results based on the forms’ UIDs.',
            ],
            'handle' => [
                'name' => 'handle',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results based on the forms’ handles.',
            ],
            'status' => [
                'name' => 'status',
                'type' => Type::listOf(Type::string()),
                'description' => 'Narrows the results based on the forms’ statuses.',
            ],
            'limit' => [
                'name' => 'limit',
                'type' => Type::int(),
                'description' => 'The number of forms to return.',
            ],
            'offset' => [
                'name' => 'offset',
                'type' => Type::int(),
                'description' => 'How many forms to skip.',
            ],
            'orderBy' => [
                'name' => 'orderBy',
                'type' => Type::string(),
                'description' => 'The field the results should be ordered by.',
            ],
        ];
    }
}
