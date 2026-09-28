<?php

declare(strict_types=1);

namespace bytesof\formable\gql\types;

use bytesof\formable\gql\interfaces\FieldInterface;
use bytesof\formable\Plugin;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

/**
 * A row within a form page - the horizontal grouping the builder lays fields
 * out in. Its source is a raw row array from the layout JSON.
 *
 * @internal
 */
final class RowType
{
    public static function getName(): string
    {
        return 'FormableRow';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'A row of fields within a form page.',
            'fields' => fn() => [
                'id' => [
                    'name' => 'id',
                    'type' => Type::string(),
                    'description' => 'The row’s ID within the layout.',
                ],
                'fields' => [
                    'name' => 'fields',
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(FieldInterface::getType()))),
                    'description' => 'The fields in this row, in order.',
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
            'fields' => self::fields($source),
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $source
     * @return array<int, \bytesof\formable\base\FormField>
     */
    private static function fields(array $source): array
    {
        $configs = is_array($source['fields'] ?? null) ? $source['fields'] : [];
        $fields = Plugin::getInstance()->getFields();
        $result = [];

        foreach ($configs as $config) {
            if (is_array($config) || is_string($config)) {
                $result[] = $fields->createField($config);
            }
        }

        return $result;
    }
}
