<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Models\Common\ApiError;

/**
 * Base exception for errors mapped from an HTTP response.
 *
 * fromResponse() parses the {error: {type, code?, message, details?}}
 * envelope and maps the type to the most specific subclass. Unknown or
 * unparseable responses fall back to a status-code mapping, then to a plain
 * ApiException. httpStatus 0 and a null apiError mean the exception did not
 * originate from an HTTP response (for example local file I/O).
 */
class ApiException extends EnvaiException
{
    /**
     * @param  array<string, list<string>>  $headers
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        string $message,
        public readonly int $httpStatus = 0,
        public readonly ?ApiError $apiError = null,
        public readonly array $headers = [],
        array $context = [],
        ?\Throwable $previous = null,
    ) {
        if ($apiError !== null) {
            $context['api_error'] ??= $apiError;
        }

        parent::__construct($message, $context, $previous);
    }

    /**
     * Maps an HTTP response to the matching exception in the hierarchy.
     */
    public static function fromResponse(HttpResponse $response, ?string $requestId = null): self
    {
        $apiError = self::parseErrorEnvelope($response);

        $context = $requestId === null ? [] : ['request_id' => $requestId];

        return self::map($apiError, $response, $context);
    }

    private static function parseErrorEnvelope(HttpResponse $response): ApiError
    {
        $body = $response->body;

        if ($body !== null && $body !== '') {
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
                    return ApiError::fromArray(self::stringKeyed($decoded['error']));
                }
            } catch (\JsonException) {
                // Fall through to the status-code synthesis below.
            }
        }

        return self::synthesizeFromStatus($response->status);
    }

    /**
     * Keeps only string keys, matching the shape json_decode produces for a
     * JSON object.
     *
     * @param  array<mixed, mixed>  $data
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private static function map(ApiError $apiError, HttpResponse $response, array $context): self
    {
        $message = $apiError->message !== '' ? $apiError->message : self::statusMessage($response->status);

        return match ($apiError->type) {
            'unauthenticated' => new AuthenticationException($message, $response->status, $apiError, $response->headers, $context),
            'access_denied' => new AccessDeniedException($message, $response->status, $apiError, $response->headers, $context),
            'not_found' => new NotFoundException($message, $response->status, $apiError, $response->headers, $context),
            'conflict' => new ConflictException($message, $response->status, $apiError, $response->headers, $context),
            'validation_error', 'invalid_request' => new ValidationException($message, $response->status, $apiError, $response->headers, $context),
            'too_many_requests' => new RateLimitException($message, $response->status, $apiError, $response->headers, self::parseRetryAfter($response->headers), $context),
            'provider_error' => new ProviderException($message, $response->status, $apiError, $response->headers, $context),
            'file_not_found', 'file_conflict', 'invalid_path', 'file_too_large', 'file_service_error', 'file_service_unavailable' => new FileException($message, $response->status, $apiError, $response->headers, $context),
            'server_error', 'internal_error' => new ServerException($message, $response->status, $apiError, $response->headers, $context),
            default => self::mapByStatus($response->status, $message, $apiError, $response->headers, $context),
        };
    }

    /**
     * @param  array<string, list<string>>  $headers
     * @param  array<string, mixed>  $context
     */
    private static function mapByStatus(int $status, string $message, ApiError $apiError, array $headers, array $context): self
    {
        return match (true) {
            $status === 401 => new AuthenticationException($message, $status, $apiError, $headers, $context),
            $status === 403 => new AccessDeniedException($message, $status, $apiError, $headers, $context),
            $status === 404 => new NotFoundException($message, $status, $apiError, $headers, $context),
            $status === 409 => new ConflictException($message, $status, $apiError, $headers, $context),
            $status === 422 => new ValidationException($message, $status, $apiError, $headers, $context),
            $status === 429 => new RateLimitException($message, $status, $apiError, $headers, self::parseRetryAfter($headers), $context),
            $status >= 500 => new ServerException($message, $status, $apiError, $headers, $context),
            default => new ApiException($message, $status, $apiError, $headers, $context),
        };
    }

    private static function synthesizeFromStatus(int $status): ApiError
    {
        $type = match (true) {
            $status === 401 => 'unauthenticated',
            $status === 403 => 'access_denied',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 422 => 'validation_error',
            $status === 429 => 'too_many_requests',
            $status >= 500 => 'server_error',
            default => 'invalid_request',
        };

        return new ApiError($type, null, self::statusMessage($status));
    }

    private static function statusMessage(int $status): string
    {
        return sprintf('Envai API responded with HTTP %d.', $status);
    }

    /**
     * Parses the Retry-After header: either a number of seconds or an
     * HTTP-date.
     *
     * @param  array<string, list<string>>  $headers
     */
    private static function parseRetryAfter(array $headers): ?float
    {
        $value = trim($headers['retry-after'][0] ?? '');

        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (float) $value;
        }

        $timestamp = strtotime($value);

        if ($timestamp === false) {
            return null;
        }

        return max(0.0, (float) ($timestamp - time()));
    }
}
