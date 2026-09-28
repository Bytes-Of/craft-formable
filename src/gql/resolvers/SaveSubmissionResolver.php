<?php

declare(strict_types=1);

namespace bytesof\formable\gql\resolvers;

use bytesof\formable\elements\Submission;
use bytesof\formable\gql\Scopes;
use bytesof\formable\models\SpamContext;
use bytesof\formable\Plugin;
use Craft;
use craft\web\Request as WebRequest;
use GraphQL\Error\Error;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * Resolves the `saveFormableSubmission` mutation.
 *
 * The submission runs the identical pipeline a posted form does - the same
 * validation, the same spam gate, the same submit rate limit (via
 * {@see \bytesof\formable\services\SubmissionGate}), the same events, so
 * notifications and integrations fire exactly as they would from the web. The
 * only thing that differs is where the values and spam signals come from
 * (arguments rather than POST) and how the outcome is reported (a payload
 * rather than a redirect).
 *
 * Access is gated twice over: the mutation only exists in the schema when a
 * form has been granted save scope, and this resolver re-checks the scope for
 * the specific form named - an argument can’t reach a form the schema wasn’t
 * given.
 *
 * @internal
 */
final class SaveSubmissionResolver
{
    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     * @throws Error
     */
    public static function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): array
    {
        $plugin = Plugin::getInstance();

        $handle = is_string($arguments['handle'] ?? null) ? $arguments['handle'] : '';
        $form = $plugin->getForms()->getFormByHandle($handle);

        if ($form === null || !$form->enabled) {
            throw new Error(Craft::t('formable', 'No form exists with the handle “{handle}”.', [
                'handle' => $handle,
            ]));
        }

        // The schema-level gate keeps the mutation out of a schema that can save
        // to no form; this keeps a schema that can save to *some* form from
        // reaching one it wasn’t granted.
        if (!Scopes::canSaveToForm($form)) {
            throw new Error(Craft::t('formable', 'Not authorized to submit the “{handle}” form.', [
                'handle' => $handle,
            ]));
        }

        // A closed form - disabled, or outside its schedule window on Pro - is
        // reported as a `closed` failure carrying its message, mirroring the web
        // controller's response rather than throwing, so a headless client can
        // tell a shut form apart from a validation error.
        if (!$form->isAcceptingSubmissions()) {
            return [
                'success' => false,
                'closed' => true,
                'submissionId' => null,
                'submission' => null,
                'errors' => [],
                'formErrors' => [$plugin->getRendering()->getClosedMessage($form)],
            ];
        }

        $submissions = $plugin->getSubmissions();
        $submission = $submissions->createSubmission($form);

        $submission->userId ??= Craft::$app->getUser()->getId();

        $fieldValues = is_array($arguments['fieldValues'] ?? null) ? $arguments['fieldValues'] : [];
        $submissions->populateSubmission($submission, self::mapFieldValues($fieldValues));

        $spam = self::spamContext($arguments);

        // This mutation reaches `submitSubmission()` directly rather than
        // through `PageFlow::complete()`, so it asks the same submit gate
        // `PageFlow` does rather than skip the check entirely - the two entry
        // points spend from the same budget ([[0088]]). Answered exactly as
        // an accepted submission, for the same reason a throttled web submit
        // is: naming the check would teach a bot how to slip past it.
        if ($plugin->getSubmissionGate()->isSubmitLimited($form, $spam->ipAddress)) {
            return self::accepted(null);
        }

        $accepted = $submissions->submitSubmission($submission, $spam);

        if (!$accepted) {
            return self::failure($submission);
        }

        // Only hand back a submission that was actually stored - a notify-only
        // form (or a spam-flagged reject) leaves nothing to return.
        return self::accepted($submission->id !== null ? $submission : null);
    }

    /**
     * Flattens the field-value inputs into a handle => value map.
     *
     * A field is populated from its `values` list when one was given, otherwise
     * from its scalar `value` - mirroring how a posted form carries either an
     * array or a single value under a handle. An entry with neither is left out
     * entirely, so it falls to the field’s default rather than being forced to
     * empty.
     *
     * @param array<int, mixed> $fieldValues
     * @return array<string, mixed>
     */
    public static function mapFieldValues(array $fieldValues): array
    {
        $mapped = [];

        foreach ($fieldValues as $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $handle = $entry['handle'] ?? null;

            if (!is_string($handle) || $handle === '') {
                continue;
            }

            if (($entry['values'] ?? null) !== null) {
                $mapped[$handle] = $entry['values'];
            } elseif (array_key_exists('value', $entry) && $entry['value'] !== null) {
                $mapped[$handle] = $entry['value'];
            }
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private static function spamContext(array $arguments): SpamContext
    {
        // A headless caller can offer a honeypot and a captcha token; the JS and
        // timing tokens are browser-planted and simply aren’t available here, so
        // a form leaning on those checks will treat a pure server submission as
        // suspicious - by design, since it can’t prove a browser was involved.
        $request = Craft::$app->getRequest();

        return new SpamContext(
            honeypot: is_string($arguments['honeypot'] ?? null) ? $arguments['honeypot'] : null,
            captchaToken: is_string($arguments['captchaValue'] ?? null) ? $arguments['captchaValue'] : null,
            // The IP feeds the blocklist and captcha checks; a console-driven
            // submission (a test, a queue replay) simply has no client IP.
            ipAddress: $request instanceof WebRequest ? $request->getUserIP() : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private static function accepted(?Submission $submission): array
    {
        return [
            'success' => true,
            'closed' => false,
            'submissionId' => $submission?->id,
            'submission' => $submission,
            'errors' => [],
            'formErrors' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function failure(Submission $submission): array
    {
        $errors = [];

        foreach ($submission->getAllFieldErrors() as $handle => $messages) {
            $errors[] = [
                'handle' => $handle,
                'messages' => $messages,
            ];
        }

        $formErrors = [];

        foreach ($submission->getNonFieldErrors() as $messages) {
            foreach ($messages as $message) {
                $formErrors[] = $message;
            }
        }

        return [
            'success' => false,
            'closed' => false,
            'submissionId' => null,
            'submission' => null,
            'errors' => $errors,
            'formErrors' => $formErrors,
        ];
    }
}
