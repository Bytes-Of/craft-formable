<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\elements\Form;
use bytesof\formable\Plugin;
use Craft;
use Throwable;
use yii\base\Component;

/**
 * Form CRUD helpers. Saving/deleting goes through Craft's element service so
 * element events remain the single extensibility surface.
 *
 * Lookup and CRUD only: the export/import format the `formable/forms` console
 * commands drive is {@see FormTransfer}'s.
 *
 * @internal
 */
final class Forms extends Component
{
    /** @var array<string, Form|null> */
    private array $_formsByHandle = [];

    public function getFormById(int $id): ?Form
    {
        return Form::find()->id($id)->status(null)->one();
    }

    public function getFormByHandle(string $handle): ?Form
    {
        if (!array_key_exists($handle, $this->_formsByHandle)) {
            $this->_formsByHandle[$handle] = Form::find()->handle($handle)->status(null)->one();
        }

        return $this->_formsByHandle[$handle];
    }

    public function getFormByUid(string $uid): ?Form
    {
        return Form::find()->uid($uid)->status(null)->one();
    }

    /**
     * @return array<int, Form>
     */
    public function getAllForms(): array
    {
        return Form::find()->status(null)->orderBy(['title' => SORT_ASC])->all();
    }

    public function getTotalForms(): int
    {
        return (int)Form::find()->status(null)->count();
    }

    /**
     * Saves a form through the element service (runs validation + element events).
     */
    public function saveForm(Form $form, bool $runValidation = true): bool
    {
        return Craft::$app->getElements()->saveElement($form, $runValidation);
    }

    /**
     * Saves a form and replaces its notifications as one unit: a notification
     * that fails to save rolls the form back with it, rather than leaving a
     * saved layout whose notifications still reference the handles it just
     * renamed. A failure is reported as an error on the form.
     *
     * @param array<int, mixed> $notifications
     */
    public function saveFormWithNotifications(Form $form, array $notifications, bool $runValidation = true): bool
    {
        $isNew = $form->id === null;
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            if (!$this->saveForm($form, $runValidation)) {
                $transaction->rollBack();

                return false;
            }

            if (!Plugin::getInstance()->getNotifications()->saveFormNotifications((int)$form->id, $notifications)) {
                $transaction->rollBack();
                $this->forgetRolledBackSave($form, $isNew);
                $form->addError('notifications', Craft::t('formable', 'The form’s notifications could not be saved.'));

                return false;
            }

            $transaction->commit();
        } catch (Throwable $e) {
            $transaction->rollBack();
            $this->forgetRolledBackSave($form, $isNew);

            throw $e;
        }

        return true;
    }

    /**
     * A new form's id pointed at a row the rollback removed; left set, a retry
     * with the same object would try to update it instead of inserting.
     */
    private function forgetRolledBackSave(Form $form, bool $isNew): void
    {
        if ($isNew) {
            $form->id = null;
            $form->uid = null;
            $form->siteSettingsId = null;
        }
    }

    /**
     * Soft-deletes a form (restorable from the trash via the element index).
     */
    public function deleteForm(Form $form): bool
    {
        return Craft::$app->getElements()->deleteElement($form);
    }
}
