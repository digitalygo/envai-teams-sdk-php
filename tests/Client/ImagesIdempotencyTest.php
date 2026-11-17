<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\RetryPolicy;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\ConflictException;
use Digitalygo\Envai\Exceptions\ProviderException;
use Digitalygo\Envai\Exceptions\RateLimitException;
use Digitalygo\Envai\Exceptions\ServerException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Exceptions\ValidationException;
use Digitalygo\Envai\Http\AuthenticationMode;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\Image\ImageGenerationAcceptedRecord;
use Digitalygo\Envai\Models\Image\ImageGenerationRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ImagesIdempotencyTest extends TestCase
{
    private static function client(FakeTransport $transport, ?RetryPolicy $retryPolicy = null): EnvaiClient
    {
        $config = new EnvaiConfig(
            baseUrl: 'https://envai.example.com',
            apiKey: new SensitiveString('sk-test-secret'),
            retryPolicy: $retryPolicy ?? new RetryPolicy,
        );

        return new EnvaiClient($config, $transport);
    }

    private static function validRequest(): ImageGenerationRequest
    {
        return new ImageGenerationRequest(
            model: 'google/nano-banana-2',
            prompt: 'A quiet alpine lake at dawn',
            webhookUrl: 'https://hooks.example.com/cb',
            aspectRatio: '16:9',
            format: 'png',
        );
    }

    private static function acceptedResponse(): HttpResponse
    {
        return new HttpResponse(
            202,
            ['content-type' => ['application/json']],
            json_encode([
                'data' => [
                    'id' => 'igr_01j5abcdefghjkmnpqrstvwxyz',
                    'status' => 'accepted',
                    'provider' => 'replicate',
                    'initial_provider' => 'replicate',
                    'routed' => false,
                    'model' => 'google/nano-banana-2',
                    'webhook_url' => 'https://hooks.example.com/cb',
                    'status_url' => 'https://envai.example.com/api/v1/images/generations/igr_01j5abcdefghjkmnpqrstvwxyz',
                    'created_at' => '2026-08-28T10:00:00+00:00',
                    'expires_at' => '2026-08-29T10:00:00+00:00',
                    'future_field' => 'preserved',
                ],
            ], JSON_THROW_ON_ERROR),
        );
    }

    private static function assertNoIdempotencyHeader(array $headers): void
    {
        foreach (array_keys($headers) as $name) {
            self::assertNotSame('idempotency-key', strtolower((string) $name));
        }
    }

    public function test_generate_without_idempotency_key_and_with_explicit_null_omit_header(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(self::acceptedResponse());
        $transport->queueResponse(self::acceptedResponse());

        $client = self::client($transport);
        $request = self::validRequest();

        $omitted = $client->images()->generate($request);
        $explicitNull = $client->images()->generate($request, null);

        self::assertInstanceOf(ImageGenerationAcceptedRecord::class, $omitted);
        self::assertInstanceOf(ImageGenerationAcceptedRecord::class, $explicitNull);
        self::assertCount(2, $transport->requests);
        self::assertCount(0, $transport->preflightRequests);

        foreach ($transport->requests as $httpRequest) {
            self::assertSame('POST', $httpRequest->method);
            self::assertSame('/api/v1/images/generations', parse_url($httpRequest->uri, PHP_URL_PATH));
            self::assertNull(parse_url($httpRequest->uri, PHP_URL_QUERY));
            self::assertSame(AuthenticationMode::Bearer, $httpRequest->auth);
            self::assertSame(['Bearer sk-test-secret'], $httpRequest->headers['authorization'] ?? null);
            self::assertSame(RetrySafety::NeverRetry, $httpRequest->retrySafety);
            self::assertNoIdempotencyHeader($httpRequest->headers);
        }
    }

    public function test_generate_with_valid_idempotency_key_preserves_wire_contract_payload_and_response(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(self::acceptedResponse());
        $transport->queueResponse(self::acceptedResponse());

        $client = self::client($transport);
        $request = self::validRequest();
        $key = 'img-Key_123.abc~!';

        $withoutKeyRecord = $client->images()->generate($request);
        $withKeyRecord = $client->images()->generate($request, $key);

        self::assertCount(2, $transport->requests);
        $withoutKeyHttp = $transport->requests[0];
        $withKeyHttp = $transport->requests[1];

        self::assertSame('POST', $withKeyHttp->method);
        self::assertSame('/api/v1/images/generations', parse_url($withKeyHttp->uri, PHP_URL_PATH));
        self::assertNull(parse_url($withKeyHttp->uri, PHP_URL_QUERY));
        self::assertSame(AuthenticationMode::Bearer, $withKeyHttp->auth);
        self::assertSame(['Bearer sk-test-secret'], $withKeyHttp->headers['authorization'] ?? null);
        self::assertSame(RetrySafety::NeverRetry, $withKeyHttp->retrySafety);
        self::assertSame([$key], $withKeyHttp->headers['idempotency-key'] ?? null);

        self::assertIsString($withoutKeyHttp->body);
        self::assertIsString($withKeyHttp->body);
        self::assertSame($withoutKeyHttp->body, $withKeyHttp->body);
        self::assertSame($request->toArray(), json_decode($withKeyHttp->body, true, 512, JSON_THROW_ON_ERROR));

        self::assertEquals($withoutKeyRecord, $withKeyRecord);
        self::assertSame('igr_01j5abcdefghjkmnpqrstvwxyz', $withKeyRecord->id);
        self::assertSame('accepted', $withKeyRecord->status->value);
        self::assertSame(['future_field' => 'preserved'], $withKeyRecord->extra);
    }

    public function test_successive_calls_on_same_service_do_not_inherit_idempotency_key(): void
    {
        $transport = new FakeTransport;
        for ($i = 0; $i < 4; $i++) {
            $transport->queueResponse(self::acceptedResponse());
        }

        $images = self::client($transport)->images();
        $request = self::validRequest();

        $images->generate($request, 'key-A');
        $images->generate($request, 'key-B');
        $images->generate($request, null);
        $images->generate($request);

        self::assertCount(4, $transport->requests);
        self::assertSame(['key-A'], $transport->requests[0]->headers['idempotency-key'] ?? null);
        self::assertSame(['key-B'], $transport->requests[1]->headers['idempotency-key'] ?? null);
        self::assertNoIdempotencyHeader($transport->requests[2]->headers);
        self::assertNoIdempotencyHeader($transport->requests[3]->headers);
    }

    public function test_explicit_caller_repeat_sends_two_explicit_requests_with_identical_key_and_payload(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(self::acceptedResponse());
        $transport->queueResponse(self::acceptedResponse());

        $images = self::client($transport)->images();
        $request = self::validRequest();
        $key = 'repeat-key-001';

        $first = $images->generate($request, $key);
        $second = $images->generate($request, $key);

        self::assertCount(2, $transport->requests);
        self::assertSame([$key], $transport->requests[0]->headers['idempotency-key'] ?? null);
        self::assertSame([$key], $transport->requests[1]->headers['idempotency-key'] ?? null);
        self::assertSame($transport->requests[0]->body, $transport->requests[1]->body);
        self::assertEquals($first, $second);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function validIdempotencyKeysProvider(): iterable
    {
        yield 'single lowercase char' => ['a'];
        yield 'single exclamation' => ['!'];
        yield 'single tilde' => ['~'];
        yield 'max 255 bytes' => [str_repeat('a', 255)];
        yield 'mixed case and punctuation' => ['!#$%&\'()*+,-./09:;<=>?@AZ[\]^_`az{|}~'];
        yield '255 bytes mixed' => ['!'.str_repeat('Az09._-~', 31).'123456'];
    }

    #[DataProvider('validIdempotencyKeysProvider')]
    public function test_valid_idempotency_key_boundaries(string $key): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(self::acceptedResponse());

        $client = self::client($transport);
        $client->images()->generate(self::validRequest(), $key);

        self::assertCount(1, $transport->requests);
        self::assertSame([$key], $transport->requests[0]->headers['idempotency-key'] ?? null);
    }

    /**
     * @return iterable<string, array{0: string}>
     */
    public static function invalidIdempotencyKeysProvider(): iterable
    {
        yield 'empty string' => [''];
        yield '256 bytes' => [str_repeat('a', 256)];
        yield 'leading space' => [' key'];
        yield 'trailing space' => ['key '];
        yield 'embedded space' => ['key value'];
        yield 'carriage return' => ["key\rvalue"];
        yield 'line feed' => ["key\nvalue"];
        yield 'crlf injection' => ["key\r\nX-Injected: 1"];
        yield 'horizontal tab' => ["key\tvalue"];
        yield 'nul byte' => ["key\x00value"];
        yield 'del byte' => ["key\x7fvalue"];
        yield 'utf8 accented' => ['chiave-è'];
        yield 'utf8 emoji' => ['key-🔑'];
        yield 'raw high byte' => ["\x80"];
    }

    #[DataProvider('invalidIdempotencyKeysProvider')]
    public function test_invalid_idempotency_keys_throw_invalid_argument_without_network_calls(string $invalidKey): void
    {
        $transport = new FakeTransport;
        $client = self::client($transport);

        try {
            $client->images()->generate(self::validRequest(), $invalidKey);
            self::fail('Invalid idempotency key must be rejected.');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('Idempotency key', $e->getMessage());
        }

        self::assertCount(0, $transport->requests);
        self::assertCount(0, $transport->preflightRequests);
    }

    public function test_local_invalid_payload_throws_validation_exception_even_with_valid_idempotency_key(): void
    {
        $transport = new FakeTransport;
        $client = self::client($transport);

        $invalidRequest = new ImageGenerationRequest(
            model: 'google/nano-banana-2',
            prompt: '   ',
            webhookUrl: 'http://127.0.0.1/cb',
        );

        try {
            $client->images()->generate($invalidRequest, 'valid-key-123');
            self::fail('Local validation must fail before any network call.');
        } catch (ValidationException $e) {
            self::assertSame(422, $e->httpStatus);
            self::assertSame('validation_error', $e->apiError()?->type);
            self::assertSame('local_validation_failed', $e->apiError()?->code);
            self::assertIsArray($e->apiError()?->details);
            self::assertNotEmpty($e->apiError()?->details);
        }

        self::assertCount(0, $transport->requests);
        self::assertCount(0, $transport->preflightRequests);
    }

    public function test_never_retry_on_network_connect_and_ambiguous_response_failures(): void
    {
        $retryPolicy = new RetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 0,
            maxDelayMs: 0,
            jitter: false,
            retryableStatuses: [409, 429, 500, 502, 503, 504],
        );

        foreach ([
            'connect failure' => new TransportException('Connection refused'),
            'ambiguous response failure' => new TransportException('Premature end of response body'),
        ] as $exception) {
            $transport = new FakeTransport;
            $transport->queueException($exception);
            $transport->queueResponse(self::acceptedResponse());

            $client = self::client($transport, $retryPolicy);

            try {
                $client->images()->generate(self::validRequest(), 'retry-check-transport');
                self::fail('TransportException must propagate.');
            } catch (TransportException $caught) {
                self::assertSame($exception, $caught);
            }

            self::assertCount(1, $transport->requests);
            self::assertSame(1, $transport->queueCount(), 'Success sentinel must remain unused.');
        }
    }

    /**
     * @return iterable<string, array{0: int, 1: string, 2: string, 3: class-string<\Throwable>}>
     */
    public static function nonRetryableErrorResponsesProvider(): iterable
    {
        yield '409 conflict' => [409, 'conflict', 'idempotency_conflict', ConflictException::class];
        yield '429 rate limit' => [429, 'too_many_requests', 'rate_limited', RateLimitException::class];
        yield '500 server error' => [500, 'server_error', 'internal_failure', ServerException::class];
        yield '502 bad gateway' => [502, 'server_error', 'bad_gateway', ServerException::class];
        yield '503 service unavailable' => [503, 'server_error', 'service_unavailable', ServerException::class];
        yield '504 gateway timeout' => [504, 'server_error', 'gateway_timeout', ServerException::class];
        yield '503 provider_error precedence' => [503, 'provider_error', 'upstream_timeout', ProviderException::class];
    }

    /**
     * @param  class-string<\Throwable>  $expectedClass
     */
    #[DataProvider('nonRetryableErrorResponsesProvider')]
    public function test_never_retry_on_http_error_statuses_and_preserves_error_mapping(
        int $status,
        string $errorType,
        string $errorCode,
        string $expectedClass,
    ): void {
        $retryPolicy = new RetryPolicy(
            maxAttempts: 3,
            baseDelayMs: 0,
            maxDelayMs: 0,
            jitter: false,
            retryableStatuses: [409, 429, 500, 502, 503, 504],
        );

        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(
            $status,
            [
                'content-type' => ['application/json'],
                'X-Request-Id' => ['req-trace-'.$status],
            ],
            json_encode([
                'error' => [
                    'type' => $errorType,
                    'code' => $errorCode,
                    'message' => 'Upstream error '.$status,
                    'details' => ['field' => 'detail-'.$status],
                ],
            ], JSON_THROW_ON_ERROR),
        ));
        $transport->queueResponse(self::acceptedResponse());

        $client = self::client($transport, $retryPolicy);

        try {
            $client->images()->generate(self::validRequest(), 'retry-check-'.$status);
            self::fail('Expected HTTP error exception.');
        } catch (\Throwable $e) {
            self::assertInstanceOf($expectedClass, $e);
            self::assertSame($status, $e->httpStatus);
            self::assertSame($errorType, $e->apiError()?->type);
            self::assertSame($errorCode, $e->apiError()?->code);
            self::assertSame(['field' => 'detail-'.$status], $e->apiError()?->details);
            self::assertSame('req-trace-'.$status, $e->requestId());
        }

        self::assertCount(1, $transport->requests);
        self::assertSame(1, $transport->queueCount(), 'Success sentinel must remain unused.');
    }

    public function test_envai_config_rejects_idempotency_key_case_insensitively(): void
    {
        foreach (['idempotency-key', 'Idempotency-Key', 'IDEMPOTENCY-KEY'] as $headerName) {
            try {
                new EnvaiConfig(
                    baseUrl: 'https://envai.example.com',
                    apiKey: new SensitiveString('sk-test-secret'),
                    headers: [$headerName => 'some-key'],
                );
                self::fail('Reserved header '.$headerName.' must be rejected.');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('controlled', $e->getMessage());
            }
        }
    }
}
