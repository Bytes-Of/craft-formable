<?php

declare(strict_types=1);

namespace bytesof\formable\gql\mutations;

use bytesof\formable\gql\resolvers\SaveSubmissionResolver;
use bytesof\formable\gql\Scopes;
use bytesof\formable\gql\types\input\FieldValueInput;
use bytesof\formable\gql\types\SubmissionMutationPayload;
use GraphQL\Type\Definition\Type;

/**
 * Registers the `saveFormableSubmission` mutation - present only for a schema
 * that has been granted save scope on at least one form.
 *
 * @internal
 */
final class Submission
{
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getMutations(): array
    {
        if (!Scopes::canSaveSubmissions()) {
            return [];
        }

        return [
            'saveFormableSubmission' => [
                'type' => Type::nonNull(SubmissionMutationPayload::getType()),
                'args' => [
                    'handle' => [
                        'name' => 'handle',
                        'type' => Type::nonNull(Type::string()),
                        'description' => 'The handle of the form being submitted.',
                    ],
                    'fieldValues' => [
                        'name' => 'fieldValues',
                        'type' => Type::listOf(Type::nonNull(FieldValueInput::getType())),
                        'description' => 'The submitted field values.',
                    ],
                    'honeypot' => [
                        'name' => 'honeypot',
                        'type' => Type::string(),
                        'description' => 'The honeypot value, when the form’s honeypot check is on.',
                    ],
                    'captchaValue' => [
                        'name' => 'captchaValue',
                        'type' => Type::string(),
                        'description' => 'The captcha response token, when the form has a captcha.',
                    ],
                ],
                'resolve' => SaveSubmissionResolver::class . '::resolve',
                'description' => 'Creates a Formable submission, running the same validation and spam checks as a posted form.',
            ],
        ];
    }
}
