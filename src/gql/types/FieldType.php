<?php

declare(strict_types=1);

namespace bytesof\formable\gql\types;

use bytesof\formable\base\FormField;
use bytesof\formable\gql\interfaces\FieldInterface;
use bytesof\formable\Plugin;
use craft\gql\base\GeneratorInterface;
use craft\gql\base\SingleGeneratorInterface;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use ReflectionClass;

/**
 * Mints one concrete GraphQL object type per registered field class, all
 * implementing {@see FieldInterface}.
 *
 * Every field type presents the same shape, so the concrete types exist purely
 * to give each a distinct `__typename` - enough for a headless client to
 * fragment on a field type - while the field definitions and resolver stay
 * shared on the interface. Plugging into Craft’s generator mechanism means the
 * concrete types are also emitted into a pre-built (introspected) schema, not
 * only discovered lazily at execution time.
 *
 * @internal
 */
final class FieldType implements GeneratorInterface, SingleGeneratorInterface
{
    /**
     * Generates (and registers) a concrete type for every registered field
     * type, keyed by GraphQL type name.
     *
     * @return array<string, ObjectType>
     */
    public static function generateTypes(mixed $context = null): array
    {
        $types = [];

        foreach (Plugin::getInstance()->getFields()->getAllFieldTypes() as $class) {
            $type = self::generateType($class);
            $types[$type->name] = $type;
        }

        return $types;
    }

    /**
     * @param class-string<FormField> $context The field class to generate a type for.
     */
    public static function generateType(mixed $context): ObjectType
    {
        /** @var class-string<FormField> $class */
        $class = $context;
        $typeName = self::typeName($class);

        /** @var ObjectType */
        return GqlEntityRegistry::getOrCreate($typeName, fn() => new ObjectType([
            'name' => $typeName,
            'interfaces' => [FieldInterface::getType()],
            'fields' => fn() => FieldInterface::getFieldDefinitions(),
            'resolveField' => FieldInterface::class . '::resolve',
        ]));
    }

    /**
     * The GraphQL type name for a field class - its short name wrapped so it
     * reads as `FormableTextField`, `FormableEmailField`, and so on.
     *
     * @param class-string<FormField> $class
     */
    public static function typeName(string $class): string
    {
        $shortName = (new ReflectionClass($class))->getShortName();

        return 'Formable' . ucfirst($shortName) . 'Field';
    }
}
