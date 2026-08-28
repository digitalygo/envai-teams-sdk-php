<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

use Digitalygo\Envai\Models\Common\ApiError;

/**
 * Base class for every exception thrown by the SDK.
 *
 * Context is carried as an associative array; the reserved keys api_error and
 * request_id are exposed through apiError() and requestId(). Messages must
 * never embed credentials.
 */
abstract class EnvaiException extends \RuntimeException
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message = '',
        protected array $context = [],
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function apiError(): ?ApiError
    {
        $apiError = $this->context['api_error'] ?? null;

        return $apiError instanceof ApiError ? $apiError : null;
    }

    public function requestId(): ?string
    {
        $requestId = $this->context['request_id'] ?? null;

        return is_string($requestId) ? $requestId : null;
    }
}
