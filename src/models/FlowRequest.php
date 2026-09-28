<?php

declare(strict_types=1);

namespace bytesof\formable\models;

use bytesof\formable\elements\Form;

/**
 * One move through a multi-page form, in the shape the flow understands.
 *
 * Everything a step needs is gathered here by whoever took the request - the
 * web controller from POST, a GraphQL resolver from its arguments - so
 * {@see \bytesof\formable\services\PageFlow} never reaches for a request of its
 * own. Values are raw: the page index is the submitter's untrusted hint, the
 * resume email is whatever they typed, and the IP and user agent are offered
 * rather than stored, since whether to keep them is the form's decision and
 * not the caller's.
 *
 * @internal
 */
final class FlowRequest
{
    /**
     * @param array<string, mixed> $values field handle => posted value
     * @param array<string, mixed> $subValues companion inputs (an email confirmation box)
     * @param int|null $page the page this move concerns, or null to use the submission's own
     * @param int|null $goto a review-step "edit" link's target page
     * @param bool $fromReview whether this move came from the review step
     * @param string|null $flowId identifies this pass through the form, so its
     *        in-flight answers key to their own session slot - planted in the
     *        form shell at render and posted back on every step
     */
    public function __construct(
        public readonly Form $form,
        public readonly string $step = PageFlowStep::SUBMIT,
        public readonly ?int $page = null,
        public readonly array $values = [],
        public readonly array $subValues = [],
        public readonly ?int $goto = null,
        public readonly bool $fromReview = false,
        public readonly ?SpamContext $spam = null,
        public readonly ?string $resumeEmail = null,
        public readonly ?int $userId = null,
        public readonly ?string $ipAddress = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $flowId = null,
    ) {
    }

    public function getSpamContext(): SpamContext
    {
        return $this->spam ?? SpamContext::empty();
    }
}
