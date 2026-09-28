<?php

declare(strict_types=1);

namespace bytesof\formable\gql\types;

use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\Type;

/**
 * What `saveFormableSubmission` returns.
 *
 * `success` speaks to whether the submission was accepted - a spam-caught
 * submission reports success, exactly as the front-end pipeline does, so a bot
 * learns nothing from the response. On a validation failure, `errors` carries
 * the per-field messages and `formErrors` the ones not tied to a field. A
 * `closed` failure - the form was disabled or outside its schedule window -
 * sets that flag and returns the closed message as a `formErrors` entry, so a
 * client can tell a shut form apart from a rejected one. Its source is the
 * plain array the resolver returns.
 *
 * @internal
 */
final class SubmissionMutationPayload
{
    public static function getName(): string
    {
        return 'FormableSubmissionMutationPayload';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'The result of a saveFormableSubmission mutation.',
            'fields' => fn() => [
                'success' => [
                    'name' => 'success',
                    'type' => Type::nonNull(Type::boolean()),
                    'description' => 'Whether the submission was accepted.',
                ],
                'closed' => [
                    'name' => 'closed',
                    'type' => Type::boolean(),
                    'description' => 'Whether the form was closed to submissions - disabled, or outside its schedule window.',
                ],
                'submissionId' => [
                    'name' => 'submissionId',
                    'type' => Type::int(),
                    'description' => 'The stored submission’s ID, when one was stored.',
                ],
                'submission' => [
                    'name' => 'submission',
                    'type' => SubmissionType::getType(),
                    'description' => 'The submission, when one was stored.',
                ],
                'errors' => [
                    'name' => 'errors',
                    'type' => Type::listOf(Type::nonNull(FieldErrorsType::getType())),
                    'description' => 'Per-field validation errors, keyed by field handle.',
                ],
                'formErrors' => [
                    'name' => 'formErrors',
                    'type' => Type::listOf(Type::nonNull(Type::string())),
                    'description' => 'Errors not tied to a particular field.',
                ],
            ],
        ]));
    }
}
