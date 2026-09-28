<?php

declare(strict_types=1);

namespace bytesof\formable\gql\types;

use craft\gql\GqlEntityRegistry;
use craft\helpers\Json;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

/**
 * A page of a form - the unit a multi-page form is walked through. Its source
 * is a raw page array from the layout JSON.
 *
 * @internal
 */
final class PageType
{
    public static function getName(): string
    {
        return 'FormablePage';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'A page of a Formable form.',
            'fields' => fn() => [
                'id' => [
                    'name' => 'id',
                    'type' => Type::string(),
                    'description' => 'The page’s ID within the layout.',
                ],
                'label' => [
                    'name' => 'label',
                    'type' => Type::string(),
                    'description' => 'The page’s label, shown on multi-page forms.',
                ],
                'settings' => [
                    'name' => 'settings',
                    'type' => Type::string(),
                    'description' => 'The page’s settings as JSON.',
                ],
                'rows' => [
                    'name' => 'rows',
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(RowType::getType()))),
                    'description' => 'The rows of fields on this page, in order.',
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
        /** @var array<string, mixed> $source */
        return match ($resolveInfo->fieldName) {
            'id' => isset($source['id']) ? (string)$source['id'] : null,
            'label' => isset($source['label']) ? (string)$source['label'] : null,
            'settings' => Json::encode(is_array($source['settings'] ?? null) ? $source['settings'] : []),
            'rows' => array_values(array_filter(
                is_array($source['rows'] ?? null) ? $source['rows'] : [],
                'is_array',
            )),
            default => null,
        };
    }
}
