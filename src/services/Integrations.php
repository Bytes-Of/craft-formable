<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\base\Integration;
use bytesof\formable\db\Table;
use bytesof\formable\elements\Submission;
use bytesof\formable\events\RegisterIntegrationTypesEvent;
use bytesof\formable\helpers\DeliveryLog;
use bytesof\formable\integrations\Hubspot;
use bytesof\formable\integrations\Mailchimp;
use bytesof\formable\integrations\Slack;
use bytesof\formable\integrations\Webhook;
use bytesof\formable\jobs\SendIntegration;
use bytesof\formable\models\ConditionSet;
use bytesof\formable\models\IntegrationResponse;
use bytesof\formable\models\ResendResult;
use bytesof\formable\Plugin;
use bytesof\formable\records\IntegrationLogRecord;
use bytesof\formable\records\IntegrationRecord;
use Craft;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use Throwable;
use yii\base\Component;

/**
 * The integrations framework: the registry of provider *types*, CRUD for the
 * named *instances* an admin configures, and the pipeline that forwards an
 * accepted submission to every integration a form has switched on.
 *
 * Integrations are a Pro feature end to end - {@see sendForSubmission()} is a
 * no-op in Lite, so a form carrying integration config downgrades gracefully
 * rather than erroring. Delivery is deferred to a queue job (per integration,
 * per submission) so a slow endpoint never holds up the submitter, and every
 * attempt lands one row in the integrations log.
 *
 * @internal
 */
final class Integrations extends Component
{
    /**
     * @event RegisterIntegrationTypesEvent Fired to register additional
     *   integration provider classes.
     *
     * @api
     */
    public const EVENT_REGISTER_INTEGRATION_TYPES = 'registerIntegrationTypes';

    private ?Conditions $_conditions = null;

    /** @var array<int, class-string<Integration>>|null */
    private ?array $_types = null;

    /**
     * Overrides the conditions engine so conditional-forwarding decisions can be
     * unit tested without a booted plugin. Mirrors {@see Notifications::setConditions()}.
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
     * Every registered provider type, as class strings. The launch set, plus
     * whatever third parties add through the registry event.
     *
     * @return array<int, class-string<Integration>>
     */
    public function getAllTypes(): array
    {
        if ($this->_types === null) {
            $event = new RegisterIntegrationTypesEvent([
                'types' => [
                    Webhook::class,
                    Slack::class,
                    Mailchimp::class,
                    Hubspot::class,
                ],
            ]);

            $this->trigger(self::EVENT_REGISTER_INTEGRATION_TYPES, $event);

            /** @var array<int, class-string<Integration>> $types */
            $types = array_values(array_unique($event->types));
            $this->_types = $types;
        }

        return $this->_types;
    }

    /**
     * The provider class registered under a type handle, or null for an unknown
     * one - so a row whose provider plugin was uninstalled is skipped, not fatal.
     *
     * @return class-string<Integration>|null
     */
    public function getTypeClass(string $type): ?string
    {
        foreach ($this->getAllTypes() as $class) {
            if ($class::type() === $type) {
                return $class;
            }
        }

        return null;
    }

    /**
     * The type catalogue the CP integration picker renders from: one entry per
     * provider, with its category, description and connection-settings schema.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTypeDefinitions(): array
    {
        return array_map(
            static function(string $class): array {
                /** @var Integration $probe */
                $probe = new $class();

                return [
                    'type' => $class::type(),
                    'name' => $class::displayName(),
                    'category' => $class::category(),
                    'description' => $class::description(),
                    'settingsSchema' => $probe->getSettingsSchema(),
                    'mappableFields' => $probe->getMappableFields(),
                ];
            },
            $this->getAllTypes(),
        );
    }

    /**
     * All configured instances, in author order.
     *
     * @return array<int, IntegrationRecord>
     */
    public function getAllIntegrations(): array
    {
        /** @var IntegrationRecord[] $records */
        $records = IntegrationRecord::find()
            ->orderBy(['sortOrder' => SORT_ASC, 'id' => SORT_ASC])
            ->all();

        return $records;
    }

    /**
     * The instances a form can actually use - enabled, of a still-registered
     * type.
     *
     * @return array<int, IntegrationRecord>
     */
    public function getEnabledIntegrations(): array
    {
        return array_values(array_filter(
            $this->getAllIntegrations(),
            fn(IntegrationRecord $record): bool => (bool)$record->enabled
                && $this->getTypeClass((string)$record->type) !== null,
        ));
    }

    public function getIntegrationById(int $id): ?IntegrationRecord
    {
        return IntegrationRecord::findOne($id);
    }

    public function getIntegrationByHandle(string $handle): ?IntegrationRecord
    {
        return IntegrationRecord::findOne(['handle' => $handle]);
    }

    /**
     * Builds the provider object for a stored instance - the record's settings
     * handed to the class named by its `type`. Null when the type is no longer
     * registered.
     */
    public function createIntegration(IntegrationRecord $record): ?Integration
    {
        $class = $this->getTypeClass((string)$record->type);

        if ($class === null) {
            return null;
        }

        return new $class($this->recordSettings($record));
    }

    /**
     * Validates and saves an instance from posted data, returning whether it
     * saved. Errors land on the passed record's `firstErrors`, so the controller
     * can surface them.
     *
     * Only settings the provider's own schema declares are kept, so a renamed or
     * removed control can't leave orphaned data behind.
     *
     * @param array<string, mixed> $data
     */
    public function saveIntegration(IntegrationRecord $record, array $data): bool
    {
        $record->name = trim((string)($data['name'] ?? ''));
        $record->type = (string)($data['type'] ?? $record->type);
        $record->enabled = (bool)($data['enabled'] ?? true);

        $class = $this->getTypeClass($record->type);

        if ($record->name === '') {
            $record->addError('name', Craft::t('formable', 'Give the integration a name.'));
        }

        if ($class === null) {
            $record->addError('type', Craft::t('formable', 'Unknown integration type.'));

            return false;
        }

        $record->handle = $this->uniqueHandle(
            trim((string)($data['handle'] ?? '')) ?: $record->name,
            $record->id !== null ? (int)$record->id : null,
        );

        $record->settings = $this->filterSettings($class, is_array($data['settings'] ?? null) ? $data['settings'] : []);

        if ($record->hasErrors()) {
            return false;
        }

        if ($record->sortOrder === null) {
            $record->sortOrder = (int)(IntegrationRecord::find()->max('[[sortOrder]]') ?? 0) + 1;
        }

        return $record->save(false);
    }

    public function deleteIntegrationById(int $id): bool
    {
        $record = IntegrationRecord::findOne($id);

        return $record === null || $record->delete() !== false;
    }

    /**
     * A handle that's unique among instances, valid as a settings-array key, and
     * derived from the name when none was given.
     */
    private function uniqueHandle(string $base, ?int $ignoreId): string
    {
        $handle = StringHelper::toHandle($base) ?: 'integration';
        $candidate = $handle;
        $suffix = 1;

        while ($this->handleTaken($candidate, $ignoreId)) {
            $suffix++;
            $candidate = $handle . $suffix;
        }

        return $candidate;
    }

    private function handleTaken(string $handle, ?int $ignoreId): bool
    {
        $query = IntegrationRecord::find()->where(['handle' => $handle]);

        if ($ignoreId !== null) {
            $query->andWhere(['not', ['id' => $ignoreId]]);
        }

        return $query->exists();
    }

    /**
     * Keeps only the keys the provider's schema declares.
     *
     * @param class-string<Integration> $class
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function filterSettings(string $class, array $settings): array
    {
        /** @var Integration $probe */
        $probe = new $class();
        $allowed = array_column($probe->getSettingsSchema(), 'name');

        return array_intersect_key($settings, array_flip($allowed));
    }

    /**
     * Queues delivery to every integration a completed submission should reach.
     *
     * The hook point for the `afterSubmit` event (see {@see Plugin}), which
     * never fires for spam - so a flagged or rejected submission forwards
     * nowhere without this having to check.
     */
    public function sendForSubmission(Submission $submission): void
    {
        if (!Plugin::getInstance()->isPro()) {
            return;
        }

        $form = $submission->getForm();

        if ($form === null || $submission->id === null) {
            return;
        }

        $perForm = $form->getFormSettings()->integrations;
        $values = $submission->getValues();

        foreach ($this->getEnabledIntegrations() as $record) {
            $config = $perForm[$record->handle] ?? null;

            if (!is_array($config) || ($config['enabled'] ?? false) !== true) {
                continue;
            }

            if (!$this->shouldSend($config, $values)) {
                continue;
            }

            Craft::$app->getQueue()->push(new SendIntegration([
                'integrationId' => (int)$record->id,
                'submissionId' => $submission->id,
            ]));
        }
    }

    /**
     * Whether a per-form integration config's conditional-forwarding rules are
     * satisfied. An absent rule set always sends.
     *
     * @param array<string, mixed> $config
     * @param array<string, mixed> $values
     */
    public function shouldSend(array $config, array $values): bool
    {
        $conditions = $config['conditions'] ?? null;

        if (!is_array($conditions) || $conditions === []) {
            return true;
        }

        return $this->getConditions()->isVisible(
            ConditionSet::fromArray($conditions),
            $values,
        );
    }

    /**
     * Error messages for a switched-on integration whose conditions or field
     * mapping name a handle the form no longer has.
     *
     * The builder rewrites both when a handle is renamed, but deleting a field,
     * importing a form or posting by hand does not. A stale rule then never
     * matches and a stale mapping is skipped by {@see resolveMapping()}, so the
     * integration quietly stops sending what it used to - the same silent
     * failure the layout and notification checks exist to refuse (see
     * internal/decisions/0098).
     *
     * Lite never runs integrations, so there is nothing for its author to fix.
     * Only integrations the plugin would actually run are checked, for the
     * reason {@see \bytesof\formable\models\FormSettings::validateIntegrationsNeedStorage()}
     * gives: an entry the author can't see must not block a save.
     *
     * @param array<string, mixed> $perForm the form's `FormSettings::$integrations`
     * @param array<int, string> $fieldHandles from {@see Layout::getFieldHandles()}
     * @return array<int, string>
     */
    public function validateFieldReferences(array $perForm, array $fieldHandles): array
    {
        $switchedOn = array_filter(
            $perForm,
            static fn(mixed $config): bool => is_array($config) && ($config['enabled'] ?? false) === true,
        );

        // Checked first so a form with no integration switched on - every Lite
        // form, and most Pro ones - never touches the database for this.
        if ($switchedOn === [] || !Plugin::getInstance()->isPro()) {
            return [];
        }

        $errors = [];

        foreach ($this->getEnabledIntegrations() as $record) {
            $config = $switchedOn[$record->handle] ?? null;

            if ($config === null) {
                continue;
            }

            $conditions = ConditionSet::fromArray(is_array($config['conditions'] ?? null) ? $config['conditions'] : null);
            $mapped = array_filter(
                is_array($config['values'] ?? null) ? $config['values'] : [],
                static fn(mixed $source): bool => is_string($source) && $source !== '',
            );

            foreach (array_diff($conditions->getFieldHandles(), $fieldHandles) as $handle) {
                $errors[] = Craft::t('formable', '{integration}: the conditions reference a field that no longer exists (“{handle}”).', [
                    'integration' => (string)$record->name,
                    'handle' => $handle,
                ]);
            }

            foreach (array_unique(array_diff($mapped, $fieldHandles)) as $handle) {
                $errors[] = Craft::t('formable', '{integration}: the field mapping uses a field that no longer exists (“{handle}”).', [
                    'integration' => (string)$record->name,
                    'handle' => $handle,
                ]);
            }
        }

        return $errors;
    }

    /**
     * Delivers one submission to one integration and logs the outcome - the work
     * the queue job runs. Returns the provider's response so the job can decide
     * whether a retry is worthwhile.
     *
     * A gone integration, submission or form, a de-registered type, or an
     * integration the form no longer enables all resolve to a non-retryable
     * result: there's nothing left to send, and retrying can't change that.
     */
    public function deliver(int $integrationId, int $submissionId): IntegrationResponse
    {
        $record = $this->getIntegrationById($integrationId);
        $submission = Plugin::getInstance()->getSubmissions()->getSubmissionById($submissionId);

        if ($record === null || !$record->enabled || $submission === null) {
            return IntegrationResponse::failed(Craft::t('formable', 'The integration or submission no longer exists.'));
        }

        $provider = $this->createIntegration($record);
        $form = $submission->getForm();

        if ($provider === null || $form === null) {
            return IntegrationResponse::failed(Craft::t('formable', 'The integration could not be built.'));
        }

        $config = $form->getFormSettings()->integrations[$record->handle] ?? null;

        if (!is_array($config) || ($config['enabled'] ?? false) !== true) {
            return IntegrationResponse::failed(Craft::t('formable', 'This form no longer sends to the integration.'));
        }

        if (!$provider->isConfigured()) {
            return IntegrationResponse::failed(Craft::t('formable', 'The integration isn’t configured.'));
        }

        $mapped = $this->resolveMapping($provider, $submission, is_array($config['values'] ?? null) ? $config['values'] : []);

        try {
            $response = $provider->send($submission, $mapped);
        } catch (Throwable $e) {
            $response = IntegrationResponse::retryable($e->getMessage());
        }

        $this->log($record, $submission, $response);

        return $response;
    }

    /**
     * Resolves an author's field mapping to the submission's values: each of the
     * provider's mappable targets to the string value of the form field mapped
     * onto it. An unmapped target is left out entirely.
     *
     * @param array<string, mixed> $values The stored `target => sourceHandle` map.
     * @return array<string, string>
     */
    private function resolveMapping(Integration $provider, Submission $submission, array $values): array
    {
        $mapped = [];
        $fields = $submission->getFormFields();

        foreach ($provider->getMappableFields() as $target) {
            $source = $values[$target['handle']] ?? null;

            if (!is_string($source) || $source === '' || !isset($fields[$source])) {
                continue;
            }

            $mapped[$target['handle']] = $fields[$source]->valueToString($submission->getValue($source));
        }

        return $mapped;
    }

    /**
     * Runs a provider's connection test against unsaved posted settings, so the
     * CP's "test connection" button works before an instance is saved.
     *
     * @param array<string, mixed> $settings
     */
    public function testConnection(string $type, array $settings): IntegrationResponse
    {
        $class = $this->getTypeClass($type);

        if ($class === null) {
            return IntegrationResponse::failed(Craft::t('formable', 'Unknown integration type.'));
        }

        try {
            return (new $class($this->filterSettings($class, $settings)))->checkConnection();
        } catch (Throwable $e) {
            return IntegrationResponse::failed($e->getMessage());
        }
    }

    private function log(IntegrationRecord $record, Submission $submission, IntegrationResponse $response): void
    {
        try {
            $log = new IntegrationLogRecord();
            $log->integrationId = (int)$record->id;
            $log->submissionId = $submission->id;
            $log->success = $response->success;
            $log->message = $response->message;

            // A body is kept only to diagnose a failure. A success's copy
            // would be a second store of the submitter's answers with nothing
            // left to learn from it.
            if (!$response->success) {
                $encrypted = $this->encryptedValues($submission);
                $log->payload = DeliveryLog::redact($response->payload, $encrypted);
                $log->response = DeliveryLog::redact($response->response, $encrypted);
            }

            $log->save(false);
        } catch (Throwable $e) {
            // The log is a convenience - a failure writing it must never mask the
            // delivery it was recording.
            Craft::warning(
                sprintf('Could not write a Formable integration log entry: %s', $e->getMessage()),
                __METHOD__,
            );
        }
    }

    /**
     * The submission's answers to fields marked Encrypt value, as sent.
     *
     * @return array<int, string>
     */
    private function encryptedValues(Submission $submission): array
    {
        $values = [];

        foreach ($submission->getFormFields() as $handle => $field) {
            if ($field->encrypted && $field::hasValue()) {
                $values[] = $field->valueToString($submission->getValue($handle));
            }
        }

        return $values;
    }

    /**
     * Recent log entries for a form (across all its integrations), newest first
     * - what the builder's Integrations tab shows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecentLogsForForm(int $formId, int $limit = 50): array
    {
        $integrations = $this->getAllIntegrations();

        if ($integrations === []) {
            return [];
        }

        // Both tables are aliased so the query is safe under a DB table prefix
        // and the shared `id`/`dateCreated` columns are never ambiguous.
        /** @var IntegrationLogRecord[] $records */
        $records = IntegrationLogRecord::find()
            ->from(['l' => Table::INTEGRATIONS_LOG])
            ->innerJoin(['s' => Table::SUBMISSIONS], '[[s.id]] = [[l.submissionId]]')
            ->where(['s.formId' => $formId])
            ->orderBy(['l.dateCreated' => SORT_DESC, 'l.id' => SORT_DESC])
            ->limit($limit)
            ->all();

        $names = [];

        foreach ($integrations as $integration) {
            $names[(int)$integration->id] = (string)$integration->name;
        }

        $resendable = $this->resendableLogIds($formId, $records);

        return array_map(
            static fn(IntegrationLogRecord $record): array => [
                'id' => (int)$record->id,
                'integration' => $names[(int)$record->integrationId] ?? Craft::t('formable', 'Integration'),
                'submissionId' => $record->submissionId !== null ? (int)$record->submissionId : null,
                'success' => (bool)$record->success,
                'message' => $record->message,
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
     * {@see DeliveryLog::latestFailures()} - narrowed to what {@see deliver()}
     * would actually attempt: an integration that is still enabled, of a
     * still-registered type, that this form still sends to. A button that could
     * only answer "this form no longer sends to the integration" is a dead
     * control.
     *
     * @param array<int, IntegrationLogRecord> $records Newest first.
     * @return array<int, int>
     */
    private function resendableLogIds(int $formId, array $records): array
    {
        // Lite forwards nothing, so it is offered no button rather than one that
        // can only refuse; the tab's upgrade prompt already says why.
        if (!Plugin::getInstance()->isPro()) {
            return [];
        }

        $perForm = Plugin::getInstance()->getForms()->getFormById($formId)?->getFormSettings()->integrations ?? [];
        $deliverable = [];

        foreach ($this->getEnabledIntegrations() as $integration) {
            $config = $perForm[$integration->handle] ?? null;

            if (is_array($config) && ($config['enabled'] ?? false) === true) {
                $deliverable[(int)$integration->id] = true;
            }
        }

        $latest = DeliveryLog::latestFailures(array_map(
            static fn(IntegrationLogRecord $record): array => [
                'id' => (int)$record->id,
                'target' => isset($deliverable[(int)$record->integrationId]) && $record->submissionId !== null
                    ? $record->integrationId . ':' . $record->submissionId
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
     * Whether a later attempt for this integration and submission has already
     * succeeded - what a queued retry checks before it runs, and what a resend
     * checks before it delivers, so the two can never both forward.
     */
    public function hasSucceeded(int $integrationId, int $submissionId): bool
    {
        return IntegrationLogRecord::find()
            ->where(['integrationId' => $integrationId, 'submissionId' => $submissionId, 'success' => true])
            ->exists();
    }

    /**
     * Delivers a logged, failed attempt again - now, inside the request - for the
     * submission it was logged against: the recovery once a provider's outage is
     * over or its credentials are fixed.
     *
     * One attempt, with no backoff queued behind it. The operator is waiting on
     * the answer, and pressing the button again is the retry. It runs through
     * {@see deliver()}, so it forwards with the integration's settings and the
     * form's mapping as they are today, and the attempt is logged like any other.
     *
     * Only the newest attempt for its integration and submission is accepted,
     * which is what stops a stale tab or a double click forwarding a lead twice.
     * A per-target mutex additionally stops two concurrent resends racing each
     * other before either has logged anything to be "newest" against, and
     * {@see hasSucceeded()} catches a queued retry that delivered between the
     * click and the lock.
     */
    public function resend(int $logId): ResendResult
    {
        if (!Plugin::getInstance()->isPro()) {
            return ResendResult::failure(Craft::t('formable', 'Resending to an integration needs Formable Pro.'));
        }

        $log = IntegrationLogRecord::findOne($logId);

        if ($log === null) {
            return ResendResult::failure(Craft::t('formable', 'That log entry no longer exists.'));
        }

        if ($log->success) {
            return ResendResult::failure(Craft::t('formable', 'That delivery already succeeded.'));
        }

        $mutex = Craft::$app->getMutex();
        $lockName = sprintf('formable:resend:integration:%d:%d', (int)$log->integrationId, (int)$log->submissionId);

        if (!$mutex->acquire($lockName)) {
            return ResendResult::failure(Craft::t('formable', 'This delivery is already being resent.'));
        }

        try {
            return $this->resendLocked($log);
        } finally {
            $mutex->release($lockName);
        }
    }

    private function resendLocked(IntegrationLogRecord $log): ResendResult
    {
        $submission = $log->submissionId !== null
            ? Plugin::getInstance()->getSubmissions()->getSubmissionById((int)$log->submissionId)
            : null;

        if ($submission === null || $submission->id === null) {
            return ResendResult::failure(Craft::t('formable', 'The submission has since been deleted.'));
        }

        if ($submission->isSpam) {
            return ResendResult::failure(Craft::t('formable', 'The submission has since been marked as spam.'));
        }

        $superseded = IntegrationLogRecord::find()
            ->where(['integrationId' => $log->integrationId, 'submissionId' => $log->submissionId])
            ->andWhere(['>', 'id', $log->id])
            ->exists();

        if ($superseded) {
            return ResendResult::failure(Craft::t('formable', 'A newer attempt for this submission is already in the log.'));
        }

        if ($this->hasSucceeded((int)$log->integrationId, (int)$log->submissionId)) {
            return ResendResult::failure(Craft::t('formable', 'A queued retry already delivered this integration.'));
        }

        $response = $this->deliver((int)$log->integrationId, $submission->id);

        return $response->success
            ? ResendResult::success(Craft::t('formable', 'Delivered again.'))
            : ResendResult::failure(
                $response->message ?? Craft::t('formable', 'It failed again. The new log entry has the details.'),
            );
    }

    /**
     * The stored settings of a record, decoded to an array.
     *
     * @return array<string, mixed>
     */
    private function recordSettings(IntegrationRecord $record): array
    {
        $settings = $record->settings;

        if (is_string($settings)) {
            $settings = Json::decodeIfJson($settings);
        }

        return is_array($settings) ? $settings : [];
    }
}
