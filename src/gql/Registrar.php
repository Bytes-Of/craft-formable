<?php

declare(strict_types=1);

namespace bytesof\formable\gql;

use bytesof\formable\gql\interfaces\FieldInterface;
use bytesof\formable\gql\mutations\Submission as SubmissionMutation;
use bytesof\formable\gql\queries\Form as FormQuery;
use bytesof\formable\gql\queries\Submission as SubmissionQuery;
use Craft;
use craft\events\RegisterGqlMutationsEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterGqlSchemaComponentsEvent;
use craft\events\RegisterGqlTypesEvent;
use craft\services\Gql;
use yii\base\Event;

/**
 * Wires Formable into Craft’s GraphQL API - types, queries, the submission
 * mutation, and the per-form schema components that gate them.
 *
 * GraphQL is a Pro feature, so the caller only invokes this on the Pro edition;
 * in Lite none of it is registered and the API simply doesn’t know Formable
 * exists, which is the graceful way to gate it (no errors, nothing to hide).
 *
 * @internal
 */
final class Registrar
{
    public static function register(): void
    {
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_TYPES,
            static function(RegisterGqlTypesEvent $event): void {
                // Registering the field interface pulls its concrete per-type
                // implementations in with it, via the generator, so they land
                // in a pre-built schema too.
                $event->types[] = FieldInterface::class;
            },
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            static function(RegisterGqlQueriesEvent $event): void {
                $event->queries = array_merge(
                    $event->queries,
                    FormQuery::getQueries(),
                    SubmissionQuery::getQueries(),
                );
            },
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_MUTATIONS,
            static function(RegisterGqlMutationsEvent $event): void {
                $event->mutations = array_merge(
                    $event->mutations,
                    SubmissionMutation::getMutations(),
                );
            },
        );

        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_SCHEMA_COMPONENTS,
            static function(RegisterGqlSchemaComponentsEvent $event): void {
                [$queries, $mutations] = SchemaComponents::build();

                $label = Craft::t('formable', 'Formable');
                $event->queries[$label] = $queries;
                $event->mutations[$label] = $mutations;
            },
        );
    }
}
