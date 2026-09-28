<?php

declare(strict_types=1);

namespace bytesof\formable\gql\queries;

use bytesof\formable\gql\arguments\FormArguments;
use bytesof\formable\gql\resolvers\FormResolver;
use bytesof\formable\gql\Scopes;
use bytesof\formable\gql\types\FormType;
use GraphQL\Type\Definition\Type;

/**
 * Registers the top-level form queries - but only for a schema that has been
 * granted read scope on at least one form, so an unauthorized schema never even
 * sees the fields in its introspection.
 *
 * @internal
 */
final class Form
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getQueries(): array
    {
        if (!Scopes::canQueryForms()) {
            return [];
        }

        return [
            'formableForms' => [
                'type' => Type::listOf(FormType::getType()),
                'args' => FormArguments::getArguments(),
                'resolve' => FormResolver::class . '::resolve',
                'description' => 'Queries Formable forms.',
            ],
            'formableForm' => [
                'type' => FormType::getType(),
                'args' => FormArguments::getArguments(),
                'resolve' => FormResolver::class . '::resolveOne',
                'description' => 'Queries a single Formable form.',
            ],
            'formableFormCount' => [
                'type' => Type::nonNull(Type::int()),
                'args' => FormArguments::getArguments(),
                'resolve' => FormResolver::class . '::resolveCount',
                'description' => 'Returns the number of Formable forms that match.',
            ],
        ];
    }
}
