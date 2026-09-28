<?php

declare(strict_types=1);

namespace bytesof\formable\variables;

use bytesof\formable\elements\db\FormQuery;
use bytesof\formable\elements\db\SubmissionQuery;
use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\models\ConditionSet;
use bytesof\formable\models\FormShell;
use bytesof\formable\Plugin;
use Craft;
use Twig\Markup;

/**
 * The `craft.formable` Twig variable - the plugin's entire template-facing API.
 *
 * @api
 */
final class FormableVariable
{
    /**
     * Renders a form.
     *
     * ```twig
     * {{ craft.formable.renderForm('contact') }}
     * {{ craft.formable.renderForm('contact', { templatePath: '_forms/contact' }) }}
     * ```
     *
     * Returns null for an unknown handle rather than throwing, so a form
     * deleted out from under a template takes out the form, not the page.
     *
     * @param array<string, mixed> $options
     */
    public function renderForm(Form|string|null $form, array $options = []): ?Markup
    {
        return Plugin::getInstance()->getRendering()->renderForm($form, $options);
    }

    /**
     * Whether the active edition is Pro.
     *
     * The template-facing side of the same gate {@see Plugin::isPro()} is for
     * services - so the front-end template pack can hide a Pro-only control
     * (the save-and-resume button) rather than render one the server rejects.
     */
    public function isPro(): bool
    {
        return Plugin::getInstance()->isPro();
    }

    /**
     * Renders a single field of a form, for authors laying out their own markup.
     *
     * @param array<string, mixed> $options
     */
    public function renderField(Form|string|null $form, string $handle, array $options = []): ?Markup
    {
        $form = is_string($form) ? $this->form($form) : $form;

        if ($form === null) {
            return null;
        }

        return Plugin::getInstance()->getRendering()->renderField($form, $handle, $options);
    }

    /**
     * Renders a form's shell - the success notice, `<form>` tag, hidden
     * inputs and error summary paired with the captcha, submit button and
     * closing tag - for an author laying fields out by hand with
     * {@see renderField()}.
     *
     * ```twig
     * {% set shell = craft.formable.renderFormShell('contact') %}
     *
     * {{ shell.open }}
     *     <div class="my-grid">
     *         {{ craft.formable.renderField('contact', 'firstName') }}
     *         {{ craft.formable.renderField('contact', 'email') }}
     *     </div>
     * {{ shell.close }}
     * ```
     *
     * Returns null for an unknown handle rather than throwing, so a form
     * deleted out from under a template takes out the form, not the page.
     *
     * @param array<string, mixed> $options
     */
    public function renderFormShell(Form|string|null $form, array $options = []): ?FormShell
    {
        $form = is_string($form) ? $this->form($form) : $form;

        if ($form === null) {
            return null;
        }

        return Plugin::getInstance()->getRendering()->renderFormShell($form, $options);
    }

    /**
     * Registers the front-end JS (and theme CSS, if enabled) without rendering
     * a form - for pages that build their markup by hand.
     *
     * @param array<string, mixed> $options
     */
    public function registerAssets(Form|string|null $form, array $options = []): void
    {
        $form = is_string($form) ? $this->form($form) : $form;

        if ($form !== null) {
            Plugin::getInstance()->getRendering()->registerAssets($form, $options);
        }
    }

    /**
     * Resolves a template-pack name against an override path.
     *
     * Templates use this for every `include` so each one falls back to the
     * plugin's pack independently:
     *
     * ```twig
     * {{ include(craft.formable.template('_row', templatePath), { … }) }}
     * ```
     */
    public function template(string $name, ?string $templatePath = null): string
    {
        return Plugin::getInstance()->getRendering()->resolveTemplatePath($name, $templatePath);
    }

    /**
     * Normalizes a raw conditions blob for the front-end, or null when it
     * isn't active or the edition doesn't run conditional logic.
     *
     * Templates use this to attach `data-formable-conditions` to a page -
     * fields expose the same through {@see \bytesof\formable\base\FormField::getConditionsConfig()}:
     *
     * ```twig
     * {% set conditions = craft.formable.conditionsData(page.settings.conditions ?? null) %}
     * ```
     *
     * @param mixed $conditions
     * @return array<string, mixed>|null
     */
    public function conditionsData(mixed $conditions): ?array
    {
        if (!Plugin::getInstance()->getConditions()->isEnabled()) {
            return null;
        }

        $set = ConditionSet::fromArray(is_array($conditions) ? $conditions : null);

        return $set->isActive() ? $set->toConfig() : null;
    }

    /**
     * Which of a form's fields are visible for a set of values, keyed by
     * handle.
     *
     * Used by the review step so it lists only what the submitter was actually
     * shown. Resolved against the localized layout, so this reuses the very
     * `FieldMap` the review page's own field objects (and their translated
     * labels) came from - see {@see \bytesof\formable\services\Conditions::resolve()}.
     *
     * @param array<string, mixed> $values
     * @return array<string, bool>
     */
    public function visibleFields(Form $form, array $values): array
    {
        $pages = Plugin::getInstance()->getTranslations()->localizePages($form);

        return Plugin::getInstance()->getConditions()->resolve($form, $values, $pages)['fields'];
    }

    /**
     * The logged-in visitor's own submissions, for a member self-service page.
     *
     * ```twig
     * {% for submission in craft.formable.submissions.form('contact').all() %}
     * ```
     *
     * A Pro feature, and scoped to the owner no matter what criteria are passed:
     * the `userId` is forced on after configuration, so a template can filter
     * within a member's submissions but never widen past them. A guest - or Lite
     * - gets a query that matches nothing rather than an error, so a template can
     * loop it unconditionally.
     *
     * @param array<string, mixed> $criteria
     * @return SubmissionQuery<int, Submission>
     */
    public function submissions(array $criteria = []): SubmissionQuery
    {
        $user = Craft::$app->getUser()->getIdentity();

        if ($user === null || !Plugin::getInstance()->isPro()) {
            return Submission::find()->id(0);
        }

        $query = Submission::find();
        Craft::configure($query, $criteria);

        // Forced last so criteria can only narrow within the owner's own rows,
        // and completed non-spam ones at that.
        $query->userId($user->id);
        $query->isIncomplete(false);
        $query->isSpam(false);

        return $query;
    }

    /**
     * One of the logged-in visitor's own submissions by id, or null if it isn't
     * theirs - the guard a "view / edit my submission" page loads through.
     */
    public function submission(int $id): ?Submission
    {
        return $this->submissions()->id($id)->one();
    }

    public function form(string $handle): ?Form
    {
        return Plugin::getInstance()->getForms()->getFormByHandle($handle);
    }

    /**
     * @param array<string, mixed> $criteria
     * @return FormQuery<int, Form>
     */
    public function forms(array $criteria = []): FormQuery
    {
        $query = Form::find();
        Craft::configure($query, $criteria);

        return $query;
    }
}
