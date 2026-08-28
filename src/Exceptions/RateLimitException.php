<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

use Digitalygo\Envai\Models\Common\ApiError;

/**
 * Thrown for HTTP 429 too_many_requests responses. retryAfterSeconds is
 * parsed from the Retry-After header when present and parseable.
 */
final class RateLimitException extends ApiException
{
    /**
     * @param  array<string, list<string>>  $headers
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        int $httpStatus = 0,
        ?ApiError $apiError = null,
        array $headers = [],
        public readonly ?float $retryAfterSeconds = null,
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $httpStatus, $apiError, $headers, $context, $previous);
    }
}
