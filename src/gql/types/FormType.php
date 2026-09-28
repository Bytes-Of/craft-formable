<?php

declare(strict_types=1);

namespace bytesof\formable\gql\types;

use bytesof\formable\elements\Form;
use bytesof\formable\gql\interfaces\FieldInterface;
use bytesof\formable\Plugin;
use craft\gql\GqlEntityRegistry;
use craft\gql\types\DateTime as DateTimeType;
use craft\helpers\Json;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

/**
 * The GraphQL type for a Formable form. Its source is a {@see Form} element.
 *
 * @internal
 */
final class FormType
{
    public static function getName(): string
    {
        return 'FormableForm';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'A Formable form.',
            'fields' => fn() => [
                'id' => [
                    'name' => 'id',
                    'type' => Type::id(),
                    'description' => 'The form’s ID.',
                ],
                'uid' => [
                    'name' => 'uid',
                    'type' => Type::string(),
                    'description' => 'The form’s UID.',
                ],
                'title' => [
                    'name' => 'title',
                    'type' => Type::string(),
                    'description' => 'The form’s title.',
                ],
                'handle' => [
                    'name' => 'handle',
                    'type' => Type::string(),
                    'description' => 'The form’s handle.',
                ],
                'enabled' => [
                    'name' => 'enabled',
                    'type' => Type::boolean(),
                    'description' => 'Whether the form is enabled.',
                ],
                'status' => [
                    'name' => 'status',
                    'type' => Type::string(),
                    'description' => 'The form’s status.',
                ],
                'dateCreated' => [
                    'name' => 'dateCreated',
                    'type' => DateTimeType::getType(),
                    'description' => 'The date the form was created.',
                ],
                'dateUpdated' => [
                    'name' => 'dateUpdated',
                    'type' => DateTimeType::getType(),
                    'description' => 'The date the form was last updated.',
                ],
                'settings' => [
                    'name' => 'settings',
                    'type' => Type::string(),
                    'description' => 'The form’s settings as JSON.',
                ],
                'pages' => [
                    'name' => 'pages',
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(PageType::getType()))),
                    'description' => 'The form’s pages, in order.',
                ],
                'fields' => [
                    'name' => 'fields',
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(FieldInterface::getType()))),
                    'description' => 'Every field on the form, flattened across pages in render order.',
                ],
            ],
            'resolveField' => self::class . '::resolve',
        ]));
    }

    /**
     * @param array<string, mixed> $arguments
     */
    public static function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var Form $source */
        return match ($resolveInfo->fieldName) {
            'id' => $source->id,
            'uid' => $source->uid,
            'title' => $source->title,
            'handle' => $source->handle,
            'enabled' => $source->enabled,
            'status' => $source->getStatus(),
            'dateCreated' => $source->dateCreated,
            'dateUpdated' => $source->dateUpdated,
            'settings' => Json::encode($source->getSettings()),
            'pages' => array_values(array_filter($source->getPages(), 'is_array')),
            'fields' => Plugin::getInstance()->getFields()->getFieldsFromLayout($source->getPages()),
            default => null,
        };
    }
}
