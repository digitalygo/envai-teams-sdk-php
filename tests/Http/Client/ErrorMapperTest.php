<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Client;

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
use Digitalygo\Envai\Http\Client\ErrorMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorMapperTest extends TestCase
{
    /**
     * @return iterable<string, array{int, string, class-string}>
     */
    public static function envelopeTypeMappingProvider(): iterable
    {
        yield 'unauthenticated' => [401, 'unauthenticated', AuthenticationException::class];
        yield 'invalid_request' => [400, 'invalid_request', ValidationException::class];
        yield 'not_found' => [404, 'not_found', NotFoundException::class];
        yield 'conflict' => [409, 'conflict', ConflictException::class];
        yield 'validation_error' => [422, 'validation_error', ValidationException::class];
        yield 'access_denied' => [403, 'access_denied', AccessDeniedException::class];
        yield 'provider_error' => [502, 'provider_error', ProviderException::class];
        yield 'server_error' => [500, 'server_error', ServerException::class];
        yield 'internal_error' => [500, 'internal_error', ServerException::class];
        yield 'file_not_found' => [404, 'file_not_found', FileException::class];
        yield 'file_conflict' => [409, 'file_conflict', FileException::class];
        yield 'invalid_path' => [400, 'invalid_path', FileException::class];
        yield 'file_too_large' => [413, 'file_too_large', FileException::class];
        yield 'file_service_error' => [500, 'file_service_error', FileException::class];
        yield 'file_service_unavailable' => [503, 'file_service_unavailable', FileException::class];
        yield 'too_many_requests' => [429, 'too_many_requests', RateLimitException::class];
    }

    /**
     * @param  class-string  $expected
     */
    #[DataProvider('envelopeTypeMappingProvider')]
    public function test_envelope_type_is_mapped_to_exception_class(int $status, string $type, string $expected): void
    {
        $body = json_encode(['error' => ['type' => $type, 'message' => 'Something went wrong.']], JSON_THROW_ON_ERROR);

        $exception = ErrorMapper::fromStatusAndBody($status, $body, []);

        $this->assertInstanceOf($expected, $exception);
        $this->assertSame('Something went wrong.', $exception->getMessage());
        $this->assertSame($status, $exception->httpStatus);
        $this->assertSame($type, $exception->apiError()?->type);
    }

    /**
     * @return iterable<string, array{int, string, class-string}>
     */
    public static function statusOnlyMappingProvider(): iterable
    {
        yield '401' => [401, 'plain text', AuthenticationException::class];
        yield '403' => [403, 'plain text', AccessDeniedException::class];
        yield '404' => [404, 'plain text', NotFoundException::class];
        yield '409' => [409, 'plain text', ConflictException::class];
        yield '422' => [422, 'plain text', ValidationException::class];
        yield '429' => [429, 'plain text', RateLimitException::class];
        yield '500' => [500, 'plain text', ServerException::class];
        yield '503' => [503, 'plain text', ServerException::class];
    }

    /**
     * @param  class-string  $expected
     */
    #[DataProvider('statusOnlyMappingProvider')]
    public function test_non_json_error_body_is_mapped_by_status(int $status, string $body, string $expected): void
    {
        $exception = ErrorMapper::fromStatusAndBody($status, $body, []);

        $this->assertInstanceOf($expected, $exception);
        $this->assertNotNull($exception->apiError());
        $this->assertSame($body, $exception->apiError()?->details);
    }

    public function test_unknown_envelope_type_with_unknown_status_falls_back_to_api_exception(): void
    {
        $exception = ErrorMapper::fromStatusAndBody(
            418,
            '{"error":{"type":"teapot_mode","message":"Short and stout."}}',
            [],
        );

        $this->assertInstanceOf(ApiException::class, $exception);
        $this->assertNotInstanceOf(ServerException::class, $exception);
        $this->assertSame(418, $exception->httpStatus);
    }

    public function test_file_type_wins_over_status(): void
    {
        // file_* types map to FileException regardless of the status.
        $exception = ErrorMapper::fromStatusAndBody(
            200,
            '{"error":{"type":"file_not_found","message":"Gone."}}',
            [],
        );

        $this->assertInstanceOf(FileException::class, $exception);
    }

    public function test_non_json_error_body_carries_truncated_body_in_details(): void
    {
        $body = str_repeat('x', 600);

        $exception = ErrorMapper::fromStatusAndBody(503, $body, []);

        $this->assertInstanceOf(ServerException::class, $exception);
        $this->assertSame(512, strlen((string) $exception->apiError()?->details) - 3);
        $this->assertStringEndsWith('...', (string) $exception->apiError()?->details);
    }

    public function test_error_code_and_details_are_preserved(): void
    {
        $exception = ErrorMapper::fromStatusAndBody(
            409,
            '{"error":{"type":"conflict","code":"turn_in_progress","message":"A turn is running.","details":{"turn_id":"cmpl_1"}}}',
            [],
        );

        $this->assertInstanceOf(ConflictException::class, $exception);
        $this->assertSame('turn_in_progress', $exception->apiError()?->code);
        $this->assertSame(['turn_id' => 'cmpl_1'], $exception->apiError()?->details);
    }

    public function test_unknown_envelope_fields_are_preserved_in_api_error_extra(): void
    {
        $exception = ErrorMapper::fromStatusAndBody(
            500,
            '{"error":{"type":"server_error","message":"Boom.","trace_id":"tr_1"}}',
            [],
        );

        $this->assertInstanceOf(ServerException::class, $exception);
        $this->assertSame(['trace_id' => 'tr_1'], $exception->apiError()?->extra);
    }

    public function test_retry_after_integer_seconds_is_parsed(): void
    {
        $exception = ErrorMapper::fromStatusAndBody(
            429,
            '{"error":{"type":"too_many_requests","message":"Slow down."}}',
            ['retry-after' => ['60']],
        );

        $this->assertInstanceOf(RateLimitException::class, $exception);
        $this->assertSame(60.0, $exception->retryAfterSeconds);
    }

    public function test_retry_after_http_date_is_parsed(): void
    {
        $future = gmdate('D, d M Y H:i:s \G\M\T', time() + 120);

        $exception = ErrorMapper::fromStatusAndBody(
            429,
            '{"error":{"type":"too_many_requests","message":"Slow down."}}',
            ['retry-after' => [$future]],
        );

        $this->assertInstanceOf(RateLimitException::class, $exception);
        $this->assertNotNull($exception->retryAfterSeconds);
        $this->assertGreaterThanOrEqual(60.0, $exception->retryAfterSeconds);
        $this->assertLessThanOrEqual(180.0, $exception->retryAfterSeconds);
    }

    public function test_retry_after_is_null_when_unparseable(): void
    {
        $exception = ErrorMapper::fromStatusAndBody(
            429,
            '{"error":{"type":"too_many_requests","message":"Slow down."}}',
            ['retry-after' => ['soon-ish']],
        );

        $this->assertInstanceOf(RateLimitException::class, $exception);
        $this->assertNull($exception->retryAfterSeconds);
    }

    public function test_retry_after_is_ignored_for_non_rate_limit_exceptions(): void
    {
        $exception = ErrorMapper::fromStatusAndBody(
            503,
            '{"error":{"type":"server_error","message":"Boom."}}',
            ['retry-after' => ['10']],
        );

        $this->assertInstanceOf(ServerException::class, $exception);
    }

    public function test_exception_messages_never_embed_authorization_credentials(): void
    {
        $secret = 'envai_sk_super_secret_value';

        $exception = ErrorMapper::fromStatusAndBody(
            401,
            '{"error":{"type":"unauthenticated","message":"Bad token."}}',
            ['authorization' => ['Bearer '.$secret]],
        );

        $this->assertInstanceOf(AuthenticationException::class, $exception);
        $this->assertStringNotContainsString($secret, $exception->getMessage());
        $this->assertStringNotContainsStringIgnoringCase('authorization', $exception->getMessage());
    }

    public function test_synthetic_message_for_empty_body(): void
    {
        $exception = ErrorMapper::fromStatusAndBody(500, null, []);

        $this->assertInstanceOf(ServerException::class, $exception);
        $this->assertSame('Envai API responded with HTTP 500.', $exception->getMessage());
        $this->assertNull($exception->apiError()?->details);
    }
}
