<?php

declare(strict_types=1);

namespace bytesof\formable\models;

/**
 * The outcome of resending one logged delivery from the control panel.
 *
 * Deliberately not {@see IntegrationResponse}: a resend is a single, operator-
 * initiated attempt with no queue behind it, so "retryable" means nothing here -
 * the operator is the retry. All the builder needs is whether it went and a
 * sentence to show them.
 *
 * @internal
 */
final class ResendResult
{
    private function __construct(
        public readonly bool $success,
        public readonly string $message,
    ) {
    }

    public static function success(string $message): self
    {
        return new self(true, $message);
    }

    public static function failure(string $message): self
    {
        return new self(false, $message);
    }
}
