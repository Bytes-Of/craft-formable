<?php

declare(strict_types=1);

namespace bytesof\formable\gql;

use bytesof\formable\elements\Form;
use craft\helpers\Gql as GqlHelper;

/**
 * The GraphQL schema-scope vocabulary for Formable.
 *
 * Access is granted per form, keyed off the form’s UID, under one entity -
 * `formableForms`. A `read` scope lets a schema query that form and its
 * submissions; a `save` scope lets it create submissions against that form
 * through the `saveFormableSubmission` mutation. Nothing here is queryable or
 * mutable by a schema that hasn’t been granted the matching scope.
 *
 * @internal
 */
final class Scopes
{
    /**
     * The scope entity all Formable components live under.
     */
    public const ENTITY = 'formableForms';

    public const ACTION_READ = 'read';
    public const ACTION_SAVE = 'save';

    /**
     * The scope-component name for reading a form (and its submissions).
     */
    public static function readComponent(string $uid): string
    {
        return self::ENTITY . '.' . $uid;
    }

    /**
     * The UIDs of the forms the active schema may take an action on, or null
     * when it may take that action on none.
     *
     * @return array<int, string>|null
     */
    public static function allowedFormUids(string $action): ?array
    {
        $allowed = GqlHelper::extractAllowedEntitiesFromSchema($action);

        if (!isset($allowed[self::ENTITY]) || !is_array($allowed[self::ENTITY])) {
            return null;
        }

        return $allowed[self::ENTITY];
    }

    public static function canQueryForms(): bool
    {
        return self::allowedFormUids(self::ACTION_READ) !== null;
    }

    public static function canSaveSubmissions(): bool
    {
        return self::allowedFormUids(self::ACTION_SAVE) !== null;
    }

    /**
     * Whether the active schema may create submissions against a given form.
     */
    public static function canSaveToForm(Form $form): bool
    {
        $uids = self::allowedFormUids(self::ACTION_SAVE);

        return $uids !== null && $form->uid !== null && in_array($form->uid, $uids, true);
    }
}
