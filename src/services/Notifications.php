<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\base\FormField;
use bytesof\formable\elements\Form;
use bytesof\formable\elements\Submission;
use bytesof\formable\fields\FileUpload;
use bytesof\formable\helpers\DeliveryLog;
use bytesof\formable\helpers\EmailText;
use bytesof\formable\jobs\SendNotification;
use bytesof\formable\models\Notification;
use bytesof\formable\models\ResendResult;
use bytesof\formable\Plugin;
use bytesof\formable\records\NotificationLogRecord;
use bytesof\formable\records\NotificationRecord;
use Craft;
use craft\elements\Asset;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;
use Throwable;
use Twig\Markup;
use yii\base\Component;
use yii\mail\MessageInterface;

/**
 * Per-form email notifications: the model CRUD the builder saves through, and
 * the delivery pipeline that fires off the back of a completed submission.
 *
 * Delivery is deferred to a queue job wherever there's a stored submission to
 * reload - so a slow SMTP server never holds up the submitter's response - and
 * every attempt, queued or inline, lands one row in the notifications log.
 *
 * @internal
 */
final class Notifications extends Component
{
    /**
     * The email template, as {@see Rendering::resolveTemplatePath()} takes it:
     * looked for under a form's override path, then under the plugin's site
     * template root.
     */
    private const TEMPLATE = 'email/notification';
    private const TEMPLATE_ROOT = 'formable';

    private ?Conditions $_conditions = null;

    /**
     * Overrides the conditions engine, so conditional-sending decisions can be
     * unit tested without a booted plugin. Mirrors {@see Submissions::setConditions()}.
     */
    public function setConditions(Conditions $conditions): void
    {
        $this->_conditions = $conditions;
    }

    public function getConditions(): Conditions
    {
        return $this->_conditions ??= Plugin::getInstance()->getConditions();
    }

    /**
     * A form's notifications, in author order.
     *
     * @return array<int, Notification>
     */
    public function getFormNotifications(int $formId): array
    {
        /** @var NotificationRecord[] $records */
        $records = NotificationRecord::find()
            ->where(['formId' => $formId])
            ->orderBy(['sortOrder' => SORT_ASC])
            ->all();

        return array_map([$this, 'recordToModel'], $records);
    }

    /**
     * The names of a form's notifications whose text still mentions a renamed
     * field's old handle inside a `{…}` token.
     *
     * A rename rewrites conditions and mappings, but a token in a subject or
     * body is author prose in a Twig-like string, and rewriting it blindly
     * could change text that only looks like a token. So they are reported,
     * not touched, and the author fixes the wording.
     *
     * @param array<string, string> $renames `oldHandle => newHandle`
     * @return array<int, string>
     */
    public function findStaleTokenNotifications(int $formId, array $renames): array
    {
        if ($renames === []) {
            return [];
        }

        $alternatives = implode('|', array_map(static fn(string $handle): string => preg_quote($handle, '/'), array_keys($renames)));
        $pattern = '/\{[^{}]*(?<![\w])(?:' . $alternatives . ')(?![\w])[^{}]*\}/';
        $stale = [];

        foreach ($this->getFormNotifications($formId) as $notification) {
            $text = implode("\n", [
                $notification->recipients,
                $notification->cc,
                $notification->bcc,
                $notification->replyTo,
                $notification->fromName,
                $notification->fromEmail,
                $notification->subject,
                $notification->body,
            ]);

            if (preg_match($pattern, $text) === 1) {
                $stale[] = $notification->name;
            }
        }

        return $stale;
    }

    public function getNotificationById(int $id): ?Notification
    {
        $record = NotificationRecord::findOne($id);

        return $record !== null ? $this->recordToModel($record) : null;
    }

    /**
     * Validates a builder payload without persisting it, returning errors keyed
     * by the notification's index in the posted list.
     *
     * @param array<int, mixed> $notifications
     * @param array<int, string> $fieldHandles the form's value-bearing field handles, from {@see Layout::getFieldHandles()} - a conditional-send rule naming any other handle is rejected
     * @return array<int, array<string, array<string>>>
     */
    public function validateNotifications(array $notifications, array $fieldHandles): array
    {
        $errors = [];

        foreach ($notifications as $index => $config) {
            $model = Notification::fromArray(is_array($config) ? $config : []);
            $modelErrors = $model->validate() ? [] : $model->getErrors();

            $unknownHandles = array_diff($model->getConditionSet()->getFieldHandles(), $fieldHandles);

            foreach ($unknownHandles as $handle) {
                $modelErrors['conditions'][] = Craft::t('formable', 'The conditions reference a field that no longer exists (“{handle}”).', [
                    'handle' => $handle,
                ]);
            }

            if ($modelErrors !== []) {
                $errors[(string)$index] = $modelErrors;
            }
        }

        return $errors;
    }

    /**
     * Replaces a form's notifications with the posted list, in one transaction:
     * existing rows are updated, new ones inserted, and any the builder dropped
     * are deleted. `sortOrder` follows the posted order.
     *
     * @param array<int, mixed> $notifications
     */
    public function saveFormNotifications(int $formId, array $notifications): bool
    {
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            $keepIds = [];

            foreach (array_values($notifications) as $index => $config) {
                $model = Notification::fromArray(is_array($config) ? $config : []);
                $model->formId = $formId;
                $model->sortOrder = $index;

                if (!$this->saveNotification($model)) {
                    $transaction->rollBack();

                    return false;
                }

                if ($model->id !== null) {
                    $keepIds[] = $model->id;
                }
            }

            $this->deleteRemoved($formId, $keepIds);
            $transaction->commit();

            return true;
        } catch (Throwable $e) {
            $transaction->rollBack();
            Craft::error(
                sprintf('Could not save Formable notifications: %s', $e->getMessage()),
                __METHOD__,
            );

            return false;
        }
    }

    public function saveNotification(Notification $model): bool
    {
        if (!$model->validate()) {
            return false;
        }

        $record = $model->id !== null ? NotificationRecord::findOne($model->id) : null;

        if ($record === null) {
            $record = new NotificationRecord();
        }

        $record->formId = $model->formId;
        $record->name = $model->name;
        $record->enabled = $model->enabled;
        $record->recipients = $model->recipients;
        $record->cc = $model->cc;
        $record->bcc = $model->bcc;
        $record->replyTo = $model->replyTo;
        $record->fromName = $model->fromName;
        $record->fromEmail = $model->fromEmail;
        $record->subject = $model->subject;
        $record->body = $model->body;
        $record->attachFiles = $model->attachFiles;
        // Assign the array directly - Craft's ActiveRecord JSON-encodes json()
        // columns on save (encoding a string here would double-encode).
        $record->conditions = $model->getConditionSet()->toConfig();
        $record->sortOrder = $model->sortOrder;
        $record->save(false);

        $model->id = (int)$record->id;
        $model->uid = $record->uid;

        return true;
    }

    public function deleteNotificationById(int $id): bool
    {
        $record = NotificationRecord::findOne($id);

        return $record === null || $record->delete() !== false;
    }

    /**
     * @param array<int, int> $keepIds
     */
    private function deleteRemoved(int $formId, array $keepIds): void
    {
        $condition = $keepIds !== []
            ? ['and', ['formId' => $formId], ['not in', 'id', $keepIds]]
            : ['formId' => $formId];

        NotificationRecord::deleteAll($condition);
    }

    /**
     * Queues (or, for an unstored submission, sends) every notification a
     * completed submission should trigger.
     *
     * The hook point for the `afterSubmit` event - see {@see Plugin}.
     */
    public function sendForSubmission(Submission $submission): void
    {
        $form = $submission->getForm();

        if ($form === null || $form->id === null) {
            return;
        }

        $values = $submission->getValues();

        // Conditional sending rides on the conditional-logic engine, which is a
        // Pro feature - in Lite every enabled notification simply sends.
        $evaluateConditions = Plugin::getInstance()->isPro();

        foreach ($this->getFormNotifications($form->id) as $notification) {
            if (!$notification->enabled) {
                continue;
            }

            if ($evaluateConditions && !$this->shouldSend($notification, $values)) {
                continue;
            }

            $this->dispatch($notification, $submission);
        }
    }

    /**
     * Whether a notification's conditional-sending rules are satisfied.
     *
     * @param array<string, mixed> $values
     */
    public function shouldSend(Notification $notification, array $values): bool
    {
        return $this->getConditions()->isVisible($notification->getConditionSet(), $values);
    }

    /**
     * Sends now via the queue where possible, in-process where not.
     *
     * A form that keeps no copy of its submissions has no row a job could
     * reload, so those go out synchronously as part of the request.
     */
    private function dispatch(Notification $notification, Submission $submission): void
    {
        if ($submission->id !== null && $notification->id !== null) {
            Craft::$app->getQueue()->push(new SendNotification([
                'submissionId' => $submission->id,
                'notificationId' => $notification->id,
            ]));

            return;
        }

        try {
            $this->send($notification, $submission);
        } catch (Throwable $e) {
            Craft::error(
                sprintf('Could not send a Formable notification: %s', $e->getMessage()),
                __METHOD__,
            );
        }
    }

    /**
     * Builds and sends one notification for one submission, logging the result.
     *
     * Returns false for a permanent problem the queue shouldn't retry (nothing
     * to send it to); re-throws a mailer exception so a transient failure
     * (SMTP down) does get retried.
     */
    public function send(Notification $notification, Submission $submission): bool
    {
        $to = $this->resolveAddresses($notification->recipients, $submission);

        if ($to === []) {
            $this->log($notification, $submission, false, [], '', Craft::t('formable', 'No valid recipients.'));

            return false;
        }

        $subject = $this->renderSubject($notification, $submission);
        $html = $this->renderHtml($notification, $submission, $subject);

        try {
            $message = $this->buildMessage($notification, $submission, $to, $subject, $html);
            $sent = $message->send();
        } catch (Throwable $e) {
            $this->log($notification, $submission, false, $to, $subject, $e->getMessage());

            // Transient - let the queue retry it.
            throw $e;
        }

        $this->log(
            $notification,
            $submission,
            $sent,
            $to,
            $subject,
            $sent ? null : Craft::t('formable', 'The mailer reported the message was not sent.'),
        );

        return $sent;
    }

    /**
     * @param array<int, string> $to
     */
    private function buildMessage(
        Notification $notification,
        Submission $submission,
        array $to,
        string $subject,
        string $html,
    ): MessageInterface {
        $message = Craft::$app->getMailer()->compose();
        $message->setTo($to);
        $message->setSubject($subject);
        $message->setHtmlBody($html);
        $message->setTextBody(EmailText::fromHtml($html));

        $cc = $this->resolveAddresses($notification->cc, $submission);

        if ($cc !== []) {
            $message->setCc($cc);
        }

        $bcc = $this->resolveAddresses($notification->bcc, $submission);

        if ($bcc !== []) {
            $message->setBcc($bcc);
        }

        $replyTo = $this->resolveAddresses($notification->replyTo, $submission);

        if ($replyTo !== []) {
            $message->setReplyTo($replyTo);
        }

        $from = $this->resolveFrom($notification, $submission);

        if ($from !== null) {
            $message->setFrom($from);
        }

        if ($notification->attachFiles) {
            $this->attachUploads($message, $submission);
        }

        return $message;
    }

    /**
     * The From address, as `[email => name]` when a name is set - or null to
     * fall back to the system default.
     *
     * @return array<string, string>|string|null
     */
    private function resolveFrom(Notification $notification, Submission $submission): array|string|null
    {
        $email = trim($this->renderString($notification->fromEmail, $submission, Rendering::CONTEXT_ADDRESS));

        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        $name = trim($this->renderString($notification->fromName, $submission, Rendering::CONTEXT_ADDRESS));

        return $name !== '' ? [$email => $name] : $email;
    }

    /**
     * Attaches every uploaded file the submission holds.
     */
    private function attachUploads(MessageInterface $message, Submission $submission): void
    {
        foreach ($submission->getFormFields() as $handle => $field) {
            if (!$field instanceof FileUpload) {
                continue;
            }

            $ids = $submission->getValue($handle);

            if (!is_array($ids)) {
                continue;
            }

            foreach ($ids as $id) {
                $asset = Asset::find()->id((int)$id)->one();

                if ($asset === null) {
                    continue;
                }

                try {
                    $message->attach($asset->getCopyOfFile(), [
                        'fileName' => $asset->getFilename(),
                        'contentType' => $asset->getMimeType(),
                    ]);
                } catch (Throwable $e) {
                    Craft::warning(
                        sprintf('Could not attach a Formable upload to a notification: %s', $e->getMessage()),
                        __METHOD__,
                    );
                }
            }
        }
    }

    /**
     * Sends a test copy of a (possibly unsaved) notification to the given
     * addresses, against a submission of sample values.
     *
     * @param array<int, string> $to
     */
    public function sendTest(Notification $notification, Submission $submission, array $to): bool
    {
        if ($to === []) {
            return false;
        }

        $subject = $this->renderSubject($notification, $submission);
        $html = $this->renderHtml($notification, $submission, $subject);

        try {
            $message = Craft::$app->getMailer()->compose();
            $message->setTo($to);
            $message->setSubject(Craft::t('formable', '[Test] {subject}', ['subject' => $subject]));
            $message->setHtmlBody($html);
            $message->setTextBody(EmailText::fromHtml($html));

            $from = $this->resolveFrom($notification, $submission);

            if ($from !== null) {
                $message->setFrom($from);
            }

            return $message->send();
        } catch (Throwable $e) {
            Craft::error(
                sprintf('Could not send a Formable test notification: %s', $e->getMessage()),
                __METHOD__,
            );

            return false;
        }
    }

    /**
     * Emails a submitter the link back to a form they parked for later.
     *
     * The token is the whole credential, so the link is the message - there is
     * nothing else to say and nothing else to look up. A send that fails is
     * logged and swallowed: the progress it points at has already been stored,
     * and failing the request would tell the submitter their form was lost when
     * it wasn't.
     */
    public function sendResumeLink(Form $form, string $email, string $token): bool
    {
        $url = UrlHelper::siteUrl('formable/resume', [
            'form' => $form->handle,
            'token' => $token,
        ]);

        try {
            return Craft::$app->getMailer()->compose()
                ->setTo($email)
                ->setSubject(Craft::t('formable', 'Finish your “{form}” submission', [
                    'form' => $form->title ?? $form->handle,
                ]))
                ->setHtmlBody(Craft::t('formable', 'Pick up where you left off: <a href="{url}">{url}</a>', [
                    'url' => $url,
                ]))
                ->setTextBody(Craft::t('formable', 'Pick up where you left off: {url}', [
                    'url' => $url,
                ]))
                ->send();
        } catch (Throwable $e) {
            Craft::error(
                sprintf('Could not send a Formable resume link: %s', $e->getMessage()),
                __METHOD__,
            );

            return false;
        }
    }

    /**
     * A submission of representative values, for previews and test sends.
     */
    public function createSampleSubmission(Form $form): Submission
    {
        $submission = Plugin::getInstance()->getSubmissions()->createSubmission($form);

        foreach ($submission->getFormFields() as $handle => $field) {
            $submission->setValue($handle, $this->sampleValue($field));
        }

        return $submission;
    }

    private function sampleValue(FormField $field): mixed
    {
        $default = $field->getDefaultValue();

        if ($default !== null && $default !== '' && $default !== []) {
            return $default;
        }

        return Craft::t('formable', 'Sample {label}', ['label' => $field->label !== '' ? $field->label : $field->handle]);
    }

    /**
     * Renders a notification's subject, falling back to a sensible default when
     * it's blank or renders empty.
     */
    private function renderSubject(Notification $notification, Submission $submission): string
    {
        $subject = trim($this->renderString($notification->subject, $submission));

        if ($subject !== '') {
            return $subject;
        }

        return Craft::t('formable', 'New submission: {form}', [
            'form' => $submission->getForm()->title ?? Craft::t('formable', 'Form'),
        ]);
    }

    /**
     * The HTML a notification sends: its body - or the all-fields table, when
     * the author left it blank - set inside the email template.
     *
     * The template resolves the way the front-end pack does, the form's Custom
     * Template Path first. After that Craft's own site-over-plugin precedence
     * applies, which is what lets `templates/formable/email/notification.twig`
     * brand every form's email at once instead of each notification pasting a
     * wrapper into its body.
     *
     * A body that is already a whole document is sent as authored: wrapping it
     * would nest one `<html>` inside another, and it is how notifications
     * written before the template existed were built. A template that fails to
     * render logs and sends the bare body - a broken wrapper should cost the
     * email its branding, not the submission its notification, and a throw here
     * would only be retried against the same broken file.
     */
    private function renderHtml(Notification $notification, Submission $submission, string $subject): string
    {
        $body = $notification->body !== ''
            ? $this->renderString($notification->body, $submission, Rendering::CONTEXT_HTML)
            : (string)$this->defaultBody($submission);

        if (self::isHtmlDocument($body)) {
            return $body;
        }

        $template = Plugin::getInstance()->getRendering()->resolveTemplatePath(
            self::TEMPLATE,
            $submission->getForm()?->getFormSettings()->templateOverridePath,
            self::TEMPLATE_ROOT,
        );

        $view = Craft::$app->getView();
        $templateMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        try {
            // Unlike renderString()'s renderObjectTemplate() call, this is an
            // ordinary Twig template render - the environment's own autoescape
            // applies to whatever a site's overriding email/notification.twig
            // prints from `values`/`submission`/`form`, so those stay
            // CONTEXT_TEXT here; encoding them again would double-escape.
            return $view->renderTemplate($template, [
                'body' => new Markup($body, Craft::$app->charset),
                'subject' => $subject,
                'notification' => $notification,
            ] + $this->variables($submission, Rendering::CONTEXT_TEXT));
        } catch (Throwable $e) {
            Craft::error(
                sprintf('Could not render the Formable email template “%s”: %s', $template, $e->getMessage()),
                __METHOD__,
            );

            return $body;
        } finally {
            $view->setTemplateMode($templateMode);
        }
    }

    /**
     * Whether an authored body is a complete HTML document rather than content
     * to set inside one.
     */
    public static function isHtmlDocument(string $html): bool
    {
        return preg_match('~^\s*(?:<!--.*?-->\s*)*<(?:!doctype|html)\b~is', $html) === 1;
    }

    /**
     * Renders an author string against the submission.
     *
     * The submission's field values are exposed both as bare tokens
     * (`{email}` → the field's value) and - for full Twig - as `submission`,
     * `form`, `values` and an `allFields` summary table. A render error logs and
     * yields an empty string rather than leaking an unrendered `{{ … }}` into an
     * email or, worse, an address field.
     *
     * `$context` is {@see Rendering::CONTEXT_TEXT} by default. For
     * {@see Rendering::CONTEXT_HTML} - the notification body, and nothing
     * else - every value in the token map and `values` is HTML-encoded before
     * Twig ever sees it, which is what closes N1: a submitted value used to
     * reach an HTML email exactly as typed, since Craft's object-template mode
     * always renders with escaping off. `allFields` is untouched - it's
     * already a safe, `Html::encode()`-built table via {@see defaultBody()} -
     * and so are `submission` and `form`, which stay out of scope for this fix
     * the same way arbitrary Twig against them does (internal/decisions/0087).
     */
    public function renderString(string $template, Submission $submission, string $context = Rendering::CONTEXT_TEXT): string
    {
        // The shared renderer makes the same check, but only after this method
        // has built the token map and the all-fields table to hand it. A
        // literal address list is the common case and is worth neither.
        if ($template === '' || !str_contains($template, '{')) {
            return $template;
        }

        return Plugin::getInstance()->getRendering()->renderObjectString(
            $template,
            $this->tokens($submission, $context),
            Rendering::ON_ERROR_EMPTY,
            'notification string',
            $this->variables($submission, $context),
            $context,
        );
    }

    /**
     * The bare-token map: each field handle to its string value, plus a couple
     * of form-level conveniences - HTML-encoded when `$context` is
     * {@see Rendering::CONTEXT_HTML}.
     *
     * @return array<string, string>
     */
    private function tokens(Submission $submission, string $context): array
    {
        $tokens = [];

        foreach ($submission->getFormFields() as $handle => $field) {
            $tokens[$handle] = $this->encodeForContext(
                $field->valueToString($submission->getValue($handle)),
                $context,
            );
        }

        $tokens['formName'] = $this->encodeForContext($submission->getForm()->title ?? '', $context);

        return $tokens;
    }

    /**
     * Extra variables for full-Twig bodies - `values` HTML-encoded the same
     * way as {@see tokens()}, and for the same reason.
     *
     * @return array<string, mixed>
     */
    private function variables(Submission $submission, string $context): array
    {
        $values = $submission->getValues();

        return [
            'submission' => $submission,
            'form' => $submission->getForm(),
            'values' => $context === Rendering::CONTEXT_HTML ? $this->encodeValuesForHtml($values) : $values,
            'allFields' => $this->defaultBody($submission),
        ];
    }

    private function encodeForContext(string $value, string $context): string
    {
        return $context === Rendering::CONTEXT_HTML ? Html::encode($value) : $value;
    }

    /**
     * Recursively HTML-encodes every string leaf of a submission's values -
     * a field can hold an array (Table, a multi-value relation), not just a
     * scalar.
     *
     * @param array<string, mixed> $values
     * @return array<string, mixed>
     */
    private function encodeValuesForHtml(array $values): array
    {
        return array_map(
            fn(mixed $value): mixed => match (true) {
                is_string($value) => Html::encode($value),
                is_array($value) => $this->encodeValuesForHtml($value),
                default => $value,
            },
            $values,
        );
    }

    /**
     * The all-fields summary table used as the default body and exposed as the
     * `allFields` variable.
     *
     * Returned as {@see Markup} so it drops into an HTML body through `{{ }}`
     * without being escaped.
     */
    public function defaultBody(Submission $submission): Markup
    {
        $rows = '';

        foreach ($submission->getFormFields() as $handle => $field) {
            if (!$field::hasValue()) {
                continue;
            }

            $value = $field->valueToString($submission->getValue($handle));
            $label = $field->label !== '' ? $field->label : $handle;

            $rows .= Html::tag('tr',
                Html::tag('th', Html::encode($label), [
                    'style' => 'text-align:left;padding:6px 12px 6px 0;vertical-align:top;',
                ]) .
                Html::tag('td', nl2br(Html::encode($value)), [
                    'style' => 'padding:6px 0;vertical-align:top;',
                ]));
        }

        $table = Html::tag('table', $rows, [
            'style' => 'border-collapse:collapse;font-family:sans-serif;font-size:14px;',
        ]);

        return new Markup($table, Craft::$app->charset);
    }

    /**
     * Renders an address field and returns the valid addresses it resolves to.
     *
     * @return array<int, string>
     */
    public function resolveAddresses(string $raw, Submission $submission): array
    {
        return self::parseAddressList($this->renderString($raw, $submission));
    }

    /**
     * Splits an author's address string into de-duplicated, valid addresses.
     *
     * Pure and framework-free, so the parsing that stands between an authored
     * (or Twig-rendered) recipient list and the mailer can be unit tested.
     *
     * @return array<int, string>
     */
    public static function parseAddressList(string $value): array
    {
        $parts = preg_split('/[,;\r\n]+/', $value) ?: [];
        $addresses = [];

        foreach ($parts as $part) {
            $part = trim($part);

            if ($part !== '' && filter_var($part, FILTER_VALIDATE_EMAIL) !== false) {
                $addresses[$part] = $part;
            }
        }

        return array_values($addresses);
    }

    /**
     * Recent log entries for a form, newest first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentLogs(int $formId, int $limit = 50): array
    {
        /** @var NotificationLogRecord[] $records */
        $records = NotificationLogRecord::find()
            ->where(['formId' => $formId])
            ->orderBy(['dateCreated' => SORT_DESC, 'id' => SORT_DESC])
            ->limit($limit)
            ->all();

        $resendable = $this->resendableLogIds($records);

        return array_map(
            static fn(NotificationLogRecord $record): array => [
                'id' => (int)$record->id,
                'notificationId' => $record->notificationId !== null ? (int)$record->notificationId : null,
                'submissionId' => $record->submissionId !== null ? (int)$record->submissionId : null,
                'success' => (bool)$record->success,
                'recipients' => (string)$record->recipients,
                'subject' => (string)$record->subject,
                'error' => $record->error,
                'date' => DeliveryLog::dateLabel($record->dateCreated),
                'submissionUrl' => DeliveryLog::submissionUrl(
                    $record->submissionId !== null ? (int)$record->submissionId : null,
                ),
                'canResend' => in_array((int)$record->id, $resendable, true),
            ],
            $records,
        );
    }

    /**
     * The log rows the builder may offer to resend - see
     * {@see DeliveryLog::latestFailures()}. A row whose notification was deleted
     * has nothing to rebuild the email from, and one logged without a stored
     * submission has nothing to render it against.
     *
     * @param array<int, NotificationLogRecord> $records Newest first.
     * @return array<int, int>
     */
    private function resendableLogIds(array $records): array
    {
        $latest = DeliveryLog::latestFailures(array_map(
            static fn(NotificationLogRecord $record): array => [
                'id' => (int)$record->id,
                'target' => $record->notificationId !== null && $record->submissionId !== null
                    ? $record->notificationId . ':' . $record->submissionId
                    : null,
                'success' => (bool)$record->success,
            ],
            $records,
        ));

        $submissionIds = [];

        foreach ($records as $record) {
            if (in_array((int)$record->id, $latest, true)) {
                $submissionIds[(int)$record->id] = (int)$record->submissionId;
            }
        }

        return DeliveryLog::withResendableSubmission($submissionIds);
    }

    /**
     * Whether a later attempt for this notification and submission has already
     * succeeded - what a queued retry checks before it runs, and what a resend
     * checks before it sends, so the two can never both deliver.
     */
    public function hasSucceeded(int $notificationId, int $submissionId): bool
    {
        return NotificationLogRecord::find()
            ->where(['notificationId' => $notificationId, 'submissionId' => $submissionId, 'success' => true])
            ->exists();
    }

    /**
     * Sends a logged, failed notification again - now, inside the request - for
     * the submission it was logged against.
     *
     * It sends the notification as it is configured today, not a snapshot of the
     * failed attempt, because fixing the cause (a mistyped recipient, a broken
     * Twig subject) and then resending is the recovery this exists for. That
     * includes a notification since disabled: `enabled` governs what a new
     * submission triggers, and an operator pressing Resend has asked for this
     * one explicitly. Conditions are not re-weighed for the same reason.
     *
     * Only the newest attempt for its notification and submission is accepted,
     * which is what stops a stale tab or a double click sending it twice. A
     * per-target mutex additionally stops two concurrent resends racing each
     * other before either has logged anything to be "newest" against, and
     * {@see hasSucceeded()} catches a queued retry that delivered between the
     * click and the lock. The attempt is logged by {@see send()} like any other.
     */
    public function resend(int $logId): ResendResult
    {
        $log = NotificationLogRecord::findOne($logId);

        if ($log === null) {
            return ResendResult::failure(Craft::t('formable', 'That log entry no longer exists.'));
        }

        if ($log->success) {
            return ResendResult::failure(Craft::t('formable', 'That notification was already sent.'));
        }

        $mutex = Craft::$app->getMutex();
        $lockName = sprintf('formable:resend:notification:%d:%d', (int)$log->notificationId, (int)$log->submissionId);

        if (!$mutex->acquire($lockName)) {
            return ResendResult::failure(Craft::t('formable', 'This delivery is already being resent.'));
        }

        try {
            return $this->resendLocked($log);
        } finally {
            $mutex->release($lockName);
        }
    }

    private function resendLocked(NotificationLogRecord $log): ResendResult
    {
        $notification = $log->notificationId !== null
            ? $this->getNotificationById((int)$log->notificationId)
            : null;

        if ($notification === null) {
            return ResendResult::failure(Craft::t('formable', 'The notification has since been deleted.'));
        }

        $submission = $log->submissionId !== null
            ? Plugin::getInstance()->getSubmissions()->getSubmissionById((int)$log->submissionId)
            : null;

        if ($submission === null) {
            return ResendResult::failure(Craft::t('formable', 'The submission has since been deleted.'));
        }

        if ($submission->isSpam) {
            return ResendResult::failure(Craft::t('formable', 'The submission has since been marked as spam.'));
        }

        $superseded = NotificationLogRecord::find()
            ->where(['notificationId' => $log->notificationId, 'submissionId' => $log->submissionId])
            ->andWhere(['>', 'id', $log->id])
            ->exists();

        if ($superseded) {
            return ResendResult::failure(Craft::t('formable', 'A newer attempt for this submission is already in the log.'));
        }

        if ($this->hasSucceeded((int)$log->notificationId, (int)$log->submissionId)) {
            return ResendResult::failure(Craft::t('formable', 'A queued retry already delivered this notification.'));
        }

        try {
            $sent = $this->send($notification, $submission);
        } catch (Throwable) {
            // send() has already written the mailer's error to a new log row,
            // which is where the refreshed log points the operator.
            $sent = false;
        }

        return $sent
            ? ResendResult::success(Craft::t('formable', 'Sent again.'))
            : ResendResult::failure(Craft::t('formable', 'It failed again. The new log entry has the details.'));
    }

    /**
     * @param array<int, string> $recipients
     */
    private function log(
        Notification $notification,
        ?Submission $submission,
        bool $success,
        array $recipients,
        string $subject,
        ?string $error,
    ): void {
        try {
            $record = new NotificationLogRecord();
            $record->notificationId = $notification->id;
            $record->submissionId = $submission?->id;
            $record->formId = $notification->formId ?? $submission?->formId;
            $record->success = $success;
            $record->recipients = implode(', ', $recipients);
            $record->subject = $subject;
            $record->error = $error;
            $record->save(false);
        } catch (Throwable $e) {
            // The log is a convenience - a failure writing it must never mask
            // (or duplicate) the send it was recording.
            Craft::warning(
                sprintf('Could not write a Formable notification log entry: %s', $e->getMessage()),
                __METHOD__,
            );
        }
    }

    private function recordToModel(NotificationRecord $record): Notification
    {
        $conditions = $record->conditions;

        if (is_string($conditions)) {
            $conditions = Json::decodeIfJson($conditions);
        }

        $model = new Notification();
        $model->id = (int)$record->id;
        $model->formId = (int)$record->formId;
        $model->name = (string)$record->name;
        $model->enabled = (bool)$record->enabled;
        $model->recipients = (string)$record->recipients;
        $model->cc = (string)$record->cc;
        $model->bcc = (string)$record->bcc;
        $model->replyTo = (string)$record->replyTo;
        $model->fromName = (string)$record->fromName;
        $model->fromEmail = (string)$record->fromEmail;
        $model->subject = (string)$record->subject;
        $model->body = (string)$record->body;
        $model->attachFiles = (bool)$record->attachFiles;
        $model->conditions = is_array($conditions) ? $conditions : [];
        $model->sortOrder = (int)$record->sortOrder;
        $model->uid = $record->uid;

        return $model;
    }
}
