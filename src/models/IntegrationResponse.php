<?php

declare(strict_types=1);

namespace bytesof\formable\models;

/**
 * The outcome of one integration call - a delivery or a connection test.
 *
 * A plain value object rather than a thrown exception: an integration failing
 * is an expected, loggable event, and the caller needs to know both whether it
 * failed and whether retrying could change that. A `retryable` failure (the
 * remote service timed out, a 5xx) is worth another attempt on a backoff; a
 * permanent one (a 400 the payload will always trigger, bad credentials) is
 * not, and re-sending it only wastes the queue.
 *
 * `payload` and `response` are the exact body sent and the raw answer
 * received. The log keeps them for a failure only, so it can be diagnosed
 * without reproducing it.
 *
 * @api
 */
final class IntegrationResponse
{
    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $response
     */
    private function __construct(
        public readonly bool $success,
        public readonly bool $retryable,
        public readonly ?string $message,
        public readonly array $payload,
        public readonly array $response,
    ) {
    }

    /**
     * A successful call.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $response
     */
    public static function success(array $payload = [], array $response = [], ?string $message = null): self
    {
        return new self(true, false, $message, $payload, $response);
    }

    /**
     * A permanent failure - nothing about re-sending the same payload would
     * make it succeed, so the queue gives up rather than retrying.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $response
     */
    public static function failed(string $message, array $payload = [], array $response = []): self
    {
        return new self(false, false, $message, $payload, $response);
    }

    /**
     * A transient failure - the service was unreachable or answered 5xx, so the
     * same call is worth retrying on a backoff.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $response
     */
    public static function retryable(string $message, array $payload = [], array $response = []): self
    {
        return new self(false, true, $message, $payload, $response);
    }
}
