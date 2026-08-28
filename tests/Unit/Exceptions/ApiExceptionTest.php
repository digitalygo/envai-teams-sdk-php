<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Unit\Exceptions;

use Digitalygo\Envai\Exceptions\AccessDeniedException;
use Digitalygo\Envai\Exceptions\ApiException;
use Digitalygo\Envai\Exceptions\AuthenticationException;
use Digitalygo\Envai\Exceptions\ConflictException;
use Digitalygo\Envai\Exceptions\FileException;
use Digitalygo\Envai\Exceptions\NotFoundException;
use Digitalygo\Envai\Exceptions\ProviderException;
use Digitalygo\Envai\Exceptions\RateLimitException;
use Digitalygo\Envai\Exceptions\ServerException;
use Digitalygo\Envai\Exceptions\ValidationException;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Models\Common\ApiError;
use PHPUnit\Framework\TestCase;

final class ApiExceptionTest extends TestCase
{
    public function test_parses_envelope_and_maps_validation_error(): void
    {
        $response = new HttpResponse(422, [], '{"error":{"type":"validation_error","code":"unknown_model","message":"Unknown model","details":{"field":"model"}}}');

        $exception = ApiException::fromResponse($response);

        $this->assertInstanceOf(ValidationException::class, $exception);
        $this->assertSame(422, $exception->httpStatus);
        $this->assertSame('validation_error', $exception->apiError?->type);
        $this->assertSame('unknown_model', $exception->apiError?->code);
        $this->assertSame('Unknown model', $exception->apiError?->message);
        $this->assertSame(['field' => 'model'], $exception->apiError?->details);
    }

    public function test_unauthenticated_maps_to_authentication_exception(): void
    {
        $exception = ApiException::fromResponse($this->envelope(401, 'unauthenticated'));

        $this->assertInstanceOf(AuthenticationException::class, $exception);
    }

    public function test_access_denied_maps_to_access_denied_exception(): void
    {
        $exception = ApiException::fromResponse($this->envelope(403, 'access_denied'));

        $this->assertInstanceOf(AccessDeniedException::class, $exception);
    }

    public function test_not_found_maps_to_not_found_exception(): void
    {
        $exception = ApiException::fromResponse($this->envelope(404, 'not_found'));

        $this->assertInstanceOf(NotFoundException::class, $exception);
    }

    public function test_conflict_maps_to_conflict_exception(): void
    {
        $exception = ApiException::fromResponse($this->envelope(409, 'conflict'));

        $this->assertInstanceOf(ConflictException::class, $exception);
    }

    public function test_provider_error_maps_to_provider_exception(): void
    {
        $exception = ApiException::fromResponse($this->envelope(502, 'provider_error'));

        $this->assertInstanceOf(ProviderException::class, $exception);
    }

    public function test_file_types_map_to_file_exception(): void
    {
        $this->assertInstanceOf(FileException::class, ApiException::fromResponse($this->envelope(404, 'file_not_found')));
        $this->assertInstanceOf(FileException::class, ApiException::fromResponse($this->envelope(409, 'file_conflict')));
        $this->assertInstanceOf(FileException::class, ApiException::fromResponse($this->envelope(413, 'file_too_large')));
    }

    public function test_server_types_map_to_server_exception(): void
    {
        $this->assertInstanceOf(ServerException::class, ApiException::fromResponse($this->envelope(500, 'server_error')));
        $this->assertInstanceOf(ServerException::class, ApiException::fromResponse($this->envelope(500, 'internal_error')));
    }

    public function test_unknown_type_falls_back_to_api_exception(): void
    {
        $response = new HttpResponse(400, [], '{"error":{"type":"some_future_type","message":"Future error"}}');

        $exception = ApiException::fromResponse($response);

        $this->assertSame(ApiException::class, $exception::class);
        $this->assertSame('some_future_type', $exception->apiError?->type);
        $this->assertSame('Future error', $exception->getMessage());
    }

    public function test_rate_limit_parses_retry_after_seconds_header(): void
    {
        $response = new HttpResponse(429, ['Retry-After' => ['30']], '{"error":{"type":"too_many_requests","message":"Slow down"}}');

        $exception = ApiException::fromResponse($response);

        $this->assertInstanceOf(RateLimitException::class, $exception);
        $this->assertSame(30.0, $exception->retryAfterSeconds);
    }

    public function test_rate_limit_parses_retry_after_http_date(): void
    {
        $response = new HttpResponse(429, ['retry-after' => ['Fri, 31 Dec 2099 23:59:59 GMT']], '{"error":{"type":"too_many_requests","message":"Slow down"}}');

        $exception = ApiException::fromResponse($response);

        $this->assertInstanceOf(RateLimitException::class, $exception);
        $this->assertNotNull($exception->retryAfterSeconds);
        $this->assertGreaterThan(0.0, $exception->retryAfterSeconds);
    }

    public function test_unparseable_body_falls_back_to_status_mapping(): void
    {
        $response = new HttpResponse(429, [], 'definitely not json');

        $exception = ApiException::fromResponse($response);

        $this->assertInstanceOf(RateLimitException::class, $exception);
        $this->assertSame('too_many_requests', $exception->apiError?->type);
        $this->assertSame('Envai API responded with HTTP 429.', $exception->getMessage());
    }

    public function test_request_id_is_propagated(): void
    {
        $exception = ApiException::fromResponse($this->envelope(500, 'server_error'), 'req_abc123');

        $this->assertSame('req_abc123', $exception->requestId());
        $this->assertSame('req_abc123', $exception->context()['request_id']);
    }

    public function test_api_error_exposes_unknown_fields_as_extra(): void
    {
        $error = ApiError::fromArray(['type' => 'conflict', 'code' => 'turn_in_progress', 'message' => 'busy', 'future_field' => true]);

        $this->assertSame(['future_field' => true], $error->extra);
    }

    private static function envelope(int $status, string $type): HttpResponse
    {
        return new HttpResponse($status, [], json_encode(['error' => ['type' => $type, 'message' => 'Something went wrong']], JSON_THROW_ON_ERROR));
    }
}
