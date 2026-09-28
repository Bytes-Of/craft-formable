<?php

declare(strict_types=1);

namespace bytesof\formable\gql\interfaces;

use bytesof\formable\base\FormField;
use bytesof\formable\gql\types\FieldType;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Json;
use GraphQL\Type\Definition\InterfaceType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

/**
 * The GraphQL interface every Formable field exposes.
 *
 * All field types share one shape - common attributes plus a JSON `settings`
 * blob - so a headless client can render any field by branching on `type` (or
 * the concrete `__typename`) without the schema growing a bespoke type per
 * setting. The concrete per-type object types are minted by {@see FieldType}
 * so `... on FormableTextField` fragments and `__typename` stay meaningful and
 * third-party field types can join the interface.
 *
 * @internal
 */
final class FieldInterface
{
    public static function getName(): string
    {
        return 'FormableFieldInterface';
    }

    /**
     * The generator Craft uses to emit the concrete implementing types into a
     * pre-built schema.
     */
    public static function getTypeGenerator(): string
    {
        return FieldType::class;
    }

    public static function getType(): Type
    {
        if ($type = GqlEntityRegistry::getEntity(self::getName())) {
            return $type;
        }

        $type = GqlEntityRegistry::createEntity(self::getName(), new InterfaceType([
            'name' => self::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'resolveType' => self::class . '::resolveType',
            'description' => 'A field on a Formable form.',
        ]));

        // Registering the interface has to bring its implementers along, or a
        // query that only ever names the interface would leave the schema with
        // no concrete type to resolve a field to.
        FieldType::generateTypes();

        return $type;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getFieldDefinitions(): array
    {
        return [
            'id' => [
                'name' => 'id',
                'type' => Type::nonNull(Type::string()),
                'description' => 'The field’s stable ID within its form layout.',
            ],
            'type' => [
                'name' => 'type',
                'type' => Type::nonNull(Type::string()),
                'description' => 'The field type’s short name, e.g. “Text” or “Email”.',
            ],
            'displayName' => [
                'name' => 'displayName',
                'type' => Type::nonNull(Type::string()),
                'description' => 'The field type’s human-readable name.',
            ],
            'label' => [
                'name' => 'label',
                'type' => Type::string(),
                'description' => 'The field’s label.',
            ],
            'handle' => [
                'name' => 'handle',
                'type' => Type::string(),
                'description' => 'The field’s handle, or null for a presentational field that collects no value.',
            ],
            'instructions' => [
                'name' => 'instructions',
                'type' => Type::string(),
                'description' => 'The field’s instructions.',
            ],
            'required' => [
                'name' => 'required',
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether the field must be answered.',
            ],
            'hasValue' => [
                'name' => 'hasValue',
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether the field collects a value from the submitter.',
            ],
            'cssClasses' => [
                'name' => 'cssClasses',
                'type' => Type::string(),
                'description' => 'Any extra CSS classes the author set on the field.',
            ],
            'conditions' => [
                'name' => 'conditions',
                'type' => Type::string(),
                'description' => 'The field’s conditional-logic rule set as JSON, or null when it has none.',
            ],
            'settings' => [
                'name' => 'settings',
                'type' => Type::string(),
                'description' => 'The field type’s own settings (everything beyond the common attributes) as JSON.',
            ],
        ];
    }

    /**
     * The concrete object type a resolved field value belongs to.
     */
    public static function resolveType(mixed $value): Type
    {
        /** @var FormField $value */
        return FieldType::generateType($value::class);
    }

    /**
     * Shared field resolver for the interface and every concrete field type.
     *
     * @param array<string, mixed> $arguments
     */
    public static function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var FormField $source */
        return match ($resolveInfo->fieldName) {
            'id' => $source->id,
            'type' => self::shortName($source),
            'displayName' => $source::displayName(),
            'label' => $source->label !== '' ? $source->label : null,
            'handle' => $source->handle !== '' ? $source->handle : null,
            'instructions' => $source->instructions !== '' ? $source->instructions : null,
            'required' => $source->required,
            'hasValue' => $source::hasValue(),
            'cssClasses' => $source->cssClasses !== '' ? $source->cssClasses : null,
            'conditions' => self::conditionsJson($source),
            'settings' => self::settingsJson($source),
            default => null,
        };
    }

    private static function shortName(FormField $field): string
    {
        $parts = explode('\\', $field::class);

        return (string)array_pop($parts);
    }

    private static function conditionsJson(FormField $field): ?string
    {
        $config = $field->getConditionsConfig();

        return $config !== null ? Json::encode($config) : null;
    }

    /**
     * The field's type-specific settings - its layout array with the attributes
     * every field shares stripped out, so `settings` carries only what makes
     * this field type distinct.
     */
    private static function settingsJson(FormField $field): string
    {
        $common = [
            'type', 'id', 'label', 'handle', 'instructions',
            'required', 'cssClasses', 'conditions', 'encrypted',
        ];

        $settings = array_diff_key($field->toLayoutArray(), array_flip($common));

        return Json::encode($settings);
    }
}
