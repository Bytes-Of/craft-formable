<?php

declare(strict_types=1);

namespace bytesof\formable\gql\types;

use bytesof\formable\elements\Submission;
use craft\gql\GqlEntityRegistry;
use craft\gql\types\DateTime as DateTimeType;
use craft\helpers\Json;
use GraphQL\Type\Definition\ObjectType;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

/**
 * The GraphQL type for a Formable submission. Its source is a {@see Submission}
 * element.
 *
 * Field values reach a client two ways: `values`, the whole set as a JSON
 * object keyed by handle in each field’s serialized form; and `fieldValues`, a
 * per-field list carrying a human-readable string. Both read through the field,
 * so an encrypted value is deciphered once - subject to the schema’s read scope
 * having been granted for the form.
 *
 * @internal
 */
final class SubmissionType
{
    public static function getName(): string
    {
        return 'FormableSubmission';
    }

    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new ObjectType([
            'name' => self::getName(),
            'description' => 'A Formable form submission.',
            'fields' => fn() => [
                'id' => [
                    'name' => 'id',
                    'type' => Type::id(),
                    'description' => 'The submission’s ID.',
                ],
                'uid' => [
                    'name' => 'uid',
                    'type' => Type::string(),
                    'description' => 'The submission’s UID.',
                ],
                'title' => [
                    'name' => 'title',
                    'type' => Type::string(),
                    'description' => 'The submission’s title - its auto-generated summary.',
                ],
                'formId' => [
                    'name' => 'formId',
                    'type' => Type::int(),
                    'description' => 'The ID of the form this submission belongs to.',
                ],
                'form' => [
                    'name' => 'form',
                    'type' => FormType::getType(),
                    'description' => 'The form this submission belongs to.',
                ],
                'status' => [
                    'name' => 'status',
                    'type' => Type::string(),
                    'description' => 'The submission’s status handle.',
                ],
                'isSpam' => [
                    'name' => 'isSpam',
                    'type' => Type::boolean(),
                    'description' => 'Whether the submission was flagged as spam.',
                ],
                'isIncomplete' => [
                    'name' => 'isIncomplete',
                    'type' => Type::boolean(),
                    'description' => 'Whether the submission is a part-filled, resumable one.',
                ],
                'userId' => [
                    'name' => 'userId',
                    'type' => Type::int(),
                    'description' => 'The ID of the logged-in user who submitted, if any.',
                ],
                'submittedSiteId' => [
                    'name' => 'submittedSiteId',
                    'type' => Type::int(),
                    'description' => 'The ID of the site the form was submitted from.',
                ],
                'submittedSiteHandle' => [
                    'name' => 'submittedSiteHandle',
                    'type' => Type::string(),
                    'description' => 'The handle of the site the form was submitted from.',
                ],
                'ipAddress' => [
                    'name' => 'ipAddress',
                    'type' => Type::string(),
                    'description' => 'The submitter’s IP address, if the form was set to collect it.',
                ],
                'dateCreated' => [
                    'name' => 'dateCreated',
                    'type' => DateTimeType::getType(),
                    'description' => 'The date the submission was created.',
                ],
                'dateUpdated' => [
                    'name' => 'dateUpdated',
                    'type' => DateTimeType::getType(),
                    'description' => 'The date the submission was last updated.',
                ],
                'values' => [
                    'name' => 'values',
                    'type' => Type::string(),
                    'description' => 'Every field value, as a JSON object keyed by field handle.',
                ],
                'fieldValues' => [
                    'name' => 'fieldValues',
                    'type' => Type::nonNull(Type::listOf(Type::nonNull(SubmissionValueType::getType()))),
                    'description' => 'Each field’s value in a readable, per-field shape.',
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
        /** @var Submission $source */
        return match ($resolveInfo->fieldName) {
            'id' => $source->id,
            'uid' => $source->uid,
            'title' => $source->title,
            'formId' => $source->formId,
            'form' => $source->getForm(),
            'status' => $source->getStatus(),
            'isSpam' => $source->isSpam,
            'isIncomplete' => $source->isIncomplete,
            'userId' => $source->userId,
            'submittedSiteId' => $source->submittedSiteId,
            'submittedSiteHandle' => $source->getSubmittedSite()?->handle,
            'ipAddress' => $source->ipAddress,
            'dateCreated' => $source->dateCreated,
            'dateUpdated' => $source->dateUpdated,
            'values' => self::valuesJson($source),
            'fieldValues' => self::fieldValues($source),
            default => null,
        };
    }

    /**
     * The submission’s values as a JSON object, each in its field’s serialized
     * (storage) form - the machine-readable counterpart to `fieldValues`.
     */
    private static function valuesJson(Submission $submission): string
    {
        $values = [];

        foreach ($submission->getFormFields() as $handle => $field) {
            $values[$handle] = $field->serializeValue($submission->getValue($handle));
        }

        return Json::encode($values);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function fieldValues(Submission $submission): array
    {
        $result = [];

        foreach ($submission->getFormFields() as $handle => $field) {
            $value = $submission->getValue($handle);

            $result[] = [
                'handle' => $handle,
                'label' => $field->label !== '' ? $field->label : null,
                'value' => $field->valueToString($value),
                'values' => is_array($value)
                    ? array_map(static fn(mixed $item): string => is_scalar($item) ? (string)$item : '', $value)
                    : null,
            ];
        }

        return $result;
    }
}
