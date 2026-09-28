<?php

declare(strict_types=1);

namespace bytesof\formable\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * A single field’s value on a submission, in a shape a client can read without
 * parsing the submission’s `values` JSON: the handle, the human-readable
 * string, and - for multi-value fields - the list of strings.
 *
 * Its source is a plain `['handle' => …, 'label' => …, 'value' => …, 'values' => …]`
 * array built by {@see SubmissionType}.
 *
 * @internal
 */
final class SubmissionValueType
{
    public static function getName(): string
    {
        return 'FormableSubmissionValue';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'A single field’s value on a submission.',
            'fields' => fn() => [
                'handle' => [
                    'name' => 'handle',
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The field’s handle.',
                ],
                'label' => [
                    'name' => 'label',
                    'type' => Type::string(),
                    'description' => 'The field’s label.',
                ],
                'value' => [
                    'name' => 'value',
                    'type' => Type::string(),
                    'description' => 'The value as a human-readable string.',
                ],
                'values' => [
                    'name' => 'values',
                    'type' => Type::listOf(Type::string()),
                    'description' => 'The individual values, for a multi-value field.',
                ],
            ],
        ]));
    }
}
