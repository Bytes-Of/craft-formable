<?php

declare(strict_types=1);

namespace bytesof\formable\widgets;

use bytesof\formable\elements\Submission;
use bytesof\formable\Plugin;
use Craft;
use craft\base\Widget;

/**
 * A dashboard widget listing the most recent submissions, with a total count -
 * the at-a-glance "what's come in" panel.
 *
 * Scopeable to one form, or left across all of them. Only offered to users who
 * can view submissions in the first place.
 *
 * @internal
 */
final class RecentSubmissions extends Widget
{
    public ?int $formId = null;
    public int $limit = 5;

    public static function isSelectable(): bool
    {
        return Craft::$app->getUser()->checkPermission('formable:viewSubmissions');
    }

    public static function displayName(): string
    {
        return Craft::t('formable', 'Recent Form Submissions');
    }

    public static function icon(): ?string
    {
        return Craft::getAlias('@formable/icon.svg') ?: null;
    }

    public function getTitle(): string
    {
        $form = $this->formId !== null
            ? Plugin::getInstance()->getForms()->getFormById($this->formId)
            : null;

        return $form !== null
            ? Craft::t('formable', '{form} - Recent Submissions', ['form' => $form->title])
            : Craft::t('formable', 'Recent Form Submissions');
    }

    public function getBodyHtml(): ?string
    {
        // Belt-and-braces: the widget can outlive the permission that placed it.
        if (!Craft::$app->getUser()->checkPermission('formable:viewSubmissions')) {
            return null;
        }

        $query = Submission::find()
            ->orderBy(['formable_submissions.dateCreated' => SORT_DESC])
            ->limit(max(1, min($this->limit, 50)));

        if ($this->formId !== null) {
            $query->formId($this->formId);
        }

        // Two counts on the same scope: the running total (which already
        // excludes spam and incomplete by the query's defaults) and, alongside
        // it, how many were caught as spam - the at-a-glance "is something
        // hammering this form" signal.
        $countQuery = Submission::find();
        $spamQuery = Submission::find()->isSpam(true);

        if ($this->formId !== null) {
            $countQuery->formId($this->formId);
            $spamQuery->formId($this->formId);
        }

        return Craft::$app->getView()->renderTemplate('formable/widgets/recent-submissions', [
            'submissions' => $query->all(),
            'total' => $countQuery->count(),
            'spam' => $spamQuery->count(),
        ]);
    }

    public function getSettingsHtml(): string
    {
        $formOptions = [
            ['label' => Craft::t('formable', 'All forms'), 'value' => ''],
        ];

        foreach (Plugin::getInstance()->getForms()->getAllForms() as $form) {
            $formOptions[] = ['label' => (string)$form->title, 'value' => (string)$form->id];
        }

        return Craft::$app->getView()->renderTemplate('formable/widgets/_settings', [
            'widget' => $this,
            'formOptions' => $formOptions,
        ]);
    }
}
