<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http\Client;

use Digitalygo\Envai\Exceptions\AccessDeniedException;
use Digitalygo\Envai\Exceptions\ApiException;
use Digitalygo\Envai\Exceptions\AuthenticationException;
use Digitalygo\Envai\Exceptions\ConflictException;
use Digitalygo\Envai\Exceptions\EnvaiException;
use Digitalygo\Envai\Exceptions\FileException;
use Digitalygo\Envai\Exceptions\NotFoundException;
use Digitalygo\Envai\Exceptions\ProviderException;
use Digitalygo\Envai\Exceptions\RateLimitException;
use Digitalygo\Envai\Exceptions\ServerException;
use Digitalygo\Envai\Exceptions\ValidationException;
use Digitalygo\Envai\Models\Common\ApiError;

/**
 * Maps an HTTP error status and body to the matching EnvaiException.
 *
 * The mapping mirrors the canonical table in ApiException::fromResponse():
 * envelope types win over status codes, then status codes win over the
 * fallback ApiException. Non-JSON or non-envelope bodies still produce the
 * right exception class carrying a synthetic ApiError with the truncated body
 * in details. Authorization headers never appear in generated messages.
 */
final class ErrorMapper
{
    /** Longest body excerpt embedded in a synthetic ApiError details. */
    private const MAX_BODY_CHARS = 512;

    /**
     * Maps an HTTP error response to the matching exception in the hierarchy.
     *
     * @param  array<string, list<string>>  $headers
     */
    public static function fromStatusAndBody(int $status, ?string $body, array $headers): EnvaiException
    {
        return self::map(self::parseApiError($status, $body), $status, $headers);
    }

    /**
     * Parses the Retry-After header: either a number of seconds or an
     * HTTP-date. Returns null when absent or unparseable.
     *
     * @param  array<string, list<string>>  $headers
     */
    public static function retryAfterSeconds(array $headers): ?float
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

    /**
     * @param  array<string, list<string>>  $headers
     */
    private static function map(ApiError $apiError, int $status, array $headers): EnvaiException
    {
        $message = $apiError->message !== '' ? $apiError->message : self::statusMessage($status);

        return match ($apiError->type) {
            'unauthenticated' => new AuthenticationException($message, $status, $apiError, $headers),
            'access_denied' => new AccessDeniedException($message, $status, $apiError, $headers),
            'not_found' => new NotFoundException($message, $status, $apiError, $headers),
            'conflict' => new ConflictException($message, $status, $apiError, $headers),
            'validation_error', 'invalid_request' => new ValidationException($message, $status, $apiError, $headers),
            'too_many_requests' => new RateLimitException($message, $status, $apiError, $headers, self::retryAfterSeconds($headers)),
            'provider_error' => new ProviderException($message, $status, $apiError, $headers),
            'file_not_found', 'file_conflict', 'invalid_path', 'file_too_large', 'file_service_error', 'file_service_unavailable' => new FileException($message, $status, $apiError, $headers),
            'server_error', 'internal_error' => new ServerException($message, $status, $apiError, $headers),
            default => self::mapByStatus($status, $message, $apiError, $headers),
        };
    }

    /**
     * @param  array<string, list<string>>  $headers
     */
    private static function mapByStatus(int $status, string $message, ApiError $apiError, array $headers): EnvaiException
    {
        return match (true) {
            $status === 401 => new AuthenticationException($message, $status, $apiError, $headers),
            $status === 403 => new AccessDeniedException($message, $status, $apiError, $headers),
            $status === 404 => new NotFoundException($message, $status, $apiError, $headers),
            $status === 409 => new ConflictException($message, $status, $apiError, $headers),
            $status === 422 => new ValidationException($message, $status, $apiError, $headers),
            $status === 429 => new RateLimitException($message, $status, $apiError, $headers, self::retryAfterSeconds($headers)),
            $status >= 500 => new ServerException($message, $status, $apiError, $headers),
            default => new ApiException($message, $status, $apiError, $headers),
        };
    }

    /**
     * Parses the {error: {type, code?, message, details?}} envelope; falls
     * back to a synthetic ApiError when the body is missing, not JSON, or not
     * an error envelope. The truncated body is embedded in details.
     */
    private static function parseApiError(int $status, ?string $body): ApiError
    {
        if ($body !== null && $body !== '') {
            try {
                $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);

                if (is_array($decoded) && isset($decoded['error']) && is_array($decoded['error'])) {
                    return ApiError::fromArray(self::stringKeyed($decoded['error']));
                }
            } catch (\JsonException) {
                // Fall through to the status-based synthesis below.
            }
        }

        return new ApiError(
            self::synthesizeType($status),
            null,
            self::statusMessage($status),
            self::truncatedBody($body),
        );
    }

    private static function synthesizeType(int $status): string
    {
        return match (true) {
            $status === 401 => 'unauthenticated',
            $status === 403 => 'access_denied',
            $status === 404 => 'not_found',
            $status === 409 => 'conflict',
            $status === 422 => 'validation_error',
            $status === 429 => 'too_many_requests',
            $status >= 500 => 'server_error',
            default => 'invalid_request',
        };
    }

    private static function statusMessage(int $status): string
    {
        return sprintf('Envai API responded with HTTP %d.', $status);
    }

    private static function truncatedBody(?string $body): ?string
    {
        if ($body === null || $body === '') {
            return null;
        }

        if (strlen($body) <= self::MAX_BODY_CHARS) {
            return $body;
        }

        return substr($body, 0, self::MAX_BODY_CHARS).'...';
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
}
