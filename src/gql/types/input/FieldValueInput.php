<?php

declare(strict_types=1);

namespace bytesof\formable\gql\types\input;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\InputObjectType;
use GraphQL\Type\Definition\Type;

/**
 * One field’s value on the way in to `saveFormableSubmission`.
 *
 * A field is either single-valued (`value`) or multi-valued (`values`, for a
 * checkbox group, multi-select, or a file field carrying several asset IDs) -
 * whichever is set is what the field is populated with, mirroring how a posted
 * form carries a scalar or an array under the same handle.
 *
 * @internal
 */
final class FieldValueInput
{
    public static function getName(): string
    {
        return 'FormableFieldValueInput';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new InputObjectType([
            'name' => self::getName(),
            'description' => 'A single field’s value for a submission.',
            'fields' => [
                'handle' => [
                    'name' => 'handle',
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The field’s handle.',
                ],
                'value' => [
                    'name' => 'value',
                    'type' => Type::string(),
                    'description' => 'The value, for a single-valued field.',
                ],
                'values' => [
                    'name' => 'values',
                    'type' => Type::listOf(Type::string()),
                    'description' => 'The values, for a multi-valued field.',
                ],
            ],
        ]));
    }
}
