<?php

declare(strict_types=1);

namespace bytesof\formable\services;

use bytesof\formable\elements\Form;
use bytesof\formable\models\PageFlowStep;
use bytesof\formable\Plugin;
use Craft;
use yii\base\Component;

/**
 * The rate limits every entry point that can complete, save or upload against
 * a submission has to clear, kept in one place so a new caller gets them by
 * construction instead of needing its own copy.
 *
 * {@see PageFlow} is the web pipeline's shape, but it is not the only door: the
 * `saveFormableSubmission` GraphQL mutation reaches {@see Submissions::submitSubmission()}
 * directly, without going through `PageFlow` at all. A check that lived only
 * inside `PageFlow::complete()` - as the submit rate limit once did - protected
 * one entry point and left the other open. Both callers now ask this service
 * instead of {@see Spam} directly, and each keeps its own throttle-logging
 * (0084) here as well, so the two stay in step.
 *
 * See internal/decisions/0088.
 *
 * @internal
 */
final class SubmissionGate extends Component
{
    private ?Spam $_spam = null;

    public function setSpamService(Spam $spam): void
    {
        $this->_spam = $spam;
    }

    public function getSpam(): Spam
    {
        return $this->_spam ??= Plugin::getInstance()->getSpam();
    }

    /**
     * Whether the submit budget for this form/IP has been spent.
     *
     * Bucketed under `submit` regardless of caller - a `next` off a
     * multi-page form's last page, an explicit submit, and the GraphQL
     * mutation all reach the same expensive pipeline, so they spend from the
     * same budget ([[0084]]).
     */
    public function isSubmitLimited(Form $form, ?string $ipAddress): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        return $this->checkLimit(
            PageFlowStep::SUBMIT,
            $form,
            $ipAddress,
            $settings->submitRateLimitMax,
            $settings->submitRateLimitWindow,
            'submit',
        );
    }

    /**
     * Whether the save-and-resume budget for this form/IP has been spent.
     */
    public function isSaveLimited(Form $form, ?string $ipAddress): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        return $this->checkLimit(
            PageFlowStep::SAVE,
            $form,
            $ipAddress,
            $settings->rateLimitMax,
            $settings->rateLimitWindow,
            'save',
        );
    }

    /**
     * Whether the upload budget for this form/IP has been spent.
     *
     * Its own bucket, spent with the submit numbers ([[0084]]) - a second,
     * independently-counted bucket keyed to the same limits, so a request
     * that carries a file doesn't spend the submit bucket twice.
     */
    public function isUploadLimited(Form $form, ?string $ipAddress): bool
    {
        $settings = Plugin::getInstance()->getSettings();

        return $this->checkLimit(
            'upload',
            $form,
            $ipAddress,
            $settings->submitRateLimitMax,
            $settings->submitRateLimitWindow,
            'upload',
        );
    }

    private function checkLimit(
        string $bucketName,
        Form $form,
        ?string $ipAddress,
        int $max,
        int $window,
        string $logLabel,
    ): bool {
        $bucket = sprintf('%s:%d:%s', $bucketName, $form->id ?? 0, $ipAddress ?? 'unknown');
        $limited = $this->getSpam()->isRateLimited($bucket, $max, $window);

        if ($limited) {
            // The IP is deliberately left out: this is a log line, not stored
            // data, and IP collection is the form's own opt-in setting
            // ([[0021]]) - a log that captured it regardless would defeat the
            // point of that being optional.
            Craft::warning(
                sprintf(
                    'Formable: %s rate limit reached for form "%s" (%d per %d seconds).',
                    $logLabel,
                    $form->handle ?? 'unknown',
                    $max,
                    $window,
                ),
                'formable',
            );
        }

        return $limited;
    }
}
