<?php

declare(strict_types=1);

namespace bytesof\formable\gql;

use bytesof\formable\elements\Form;
use Craft;

/**
 * Builds the per-form schema components shown in the CP’s GraphQL schema editor.
 *
 * Each form contributes a read component (query the form and its submissions)
 * and a save component (create submissions through the mutation), keyed off the
 * form’s UID so a schema’s grants survive a form being renamed.
 *
 * @internal
 */
final class SchemaComponents
{
    /**
     * @return array{0: array<string, array<string, mixed>>, 1: array<string, array<string, mixed>>}
     */
    public static function build(): array
    {
        $queries = [];
        $mutations = [];

        foreach (Form::find()->status(null)->all() as $form) {
            if ($form->uid === null) {
                continue;
            }

            $name = (string)$form->title;
            $prefix = Scopes::readComponent($form->uid);

            $queries["$prefix:read"] = [
                'label' => Craft::t('formable', 'Query the “{name}” form and its submissions', ['name' => $name]),
            ];

            $mutations["$prefix:save"] = [
                'label' => Craft::t('formable', 'Submit to the “{name}” form', ['name' => $name]),
            ];
        }

        return [$queries, $mutations];
    }
}
