<?php

declare(strict_types=1);

namespace bytesof\formable\models;

/**
 * The raw spam signals pulled off a submission request, in one bag the spam
 * service can weigh.
 *
 * Gathering these into a value object (rather than reaching into the request
 * from the spam service) keeps the service free of any one caller's shape - the
 * web controller builds one from POST fields, and the GraphQL mutation will
 * build one from its arguments, both feeding the identical checks.
 *
 * @internal
 */
final class SpamContext
{
    public function __construct(
        public readonly ?string $honeypot = null,
        public readonly ?string $jsToken = null,
        public readonly ?string $timeToken = null,
        public readonly ?string $captchaToken = null,
        public readonly ?string $ipAddress = null,
    ) {
    }

    /**
     * A context carrying no signals - what a caller with nothing to offer (a
     * console-driven submit, a test) passes so the checks that depend on a
     * signal simply don't fire.
     */
    public static function empty(): self
    {
        return new self();
    }
}
