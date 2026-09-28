<?php

declare(strict_types=1);

namespace bytesof\formable\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * A field’s validation errors - GraphQL can’t key an object by a dynamic field
 * handle, so the per-field messages come back as a list of these instead. Its
 * source is a plain `['handle' => …, 'messages' => [...]]` array.
 *
 * @internal
 */
final class FieldErrorsType
{
    public static function getName(): string
    {
        return 'FormableFieldErrors';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'The validation errors for one field.',
            'fields' => fn() => [
                'handle' => [
                    'name' => 'handle',
                    'type' => Type::nonNull(Type::string()),
                    'description' => 'The field’s handle.',
                ],
                'messages' => [
                    'name' => 'messages',
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(Type::string()))),
                    'description' => 'The error messages for the field.',
                ],
            ],
        ]));
    }
}
