<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Client;

use Digitalygo\Envai\Configuration\RetryPolicy;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\NotFoundException;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Exceptions\ServerException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Http\AuthenticationMode;
use Digitalygo\Envai\Http\Client\EnvelopeMode;
use Digitalygo\Envai\Http\Client\RequestExecutor;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\RetrySafety;
use Digitalygo\Envai\Models\Common\ResourceCollection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class RequestExecutorTest extends TestCase
{
    private const API_KEY = 'envai_sk_test_secret_value';

    private FakeTransport $transport;

    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->transport = new FakeTransport;
        $this->logger = new RecordingLogger;
    }

    private function executor(?RetryPolicy $policy = null, bool $withApiKey = true, ?\Closure $sleepMicroseconds = null): RequestExecutor
    {
        return new RequestExecutor(
            $this->transport,
            new UriResolver('https://api.envai.dev'),
            $policy ?? new RetryPolicy(baseDelayMs: 0, jitter: false),
            $this->logger,
            $withApiKey ? new SensitiveString(self::API_KEY) : null,
            $sleepMicroseconds,
        );
    }

    public function test_injects_bearer_authorization_header(): void
    {
        $this->transport->queueResponse(new HttpResponse(200, body: '{"data":{}}'));

        $this->executor()->send(HttpRequest::get('https://api.envai.dev/up'));

        $this->assertSame(['Bearer '.self::API_KEY], $this->transport->requests[0]->headers['authorization']);
    }

    public function test_does_not_inject_authorization_without_api_key(): void
    {
        $this->transport->queueResponse(new HttpResponse(200));

        $this->executor(withApiKey: false)->send(HttpRequest::get('https://api.envai.dev/up'));

        $this->assertArrayNotHasKey('authorization', $this->transport->requests[0]->headers);
    }

    public function test_does_not_inject_authorization_for_none_auth_mode(): void
    {
        $this->transport->queueResponse(new HttpResponse(200));

        $this->executor()->send(HttpRequest::get('https://api.envai.dev/up')->withAuth(AuthenticationMode::None));

        $this->assertArrayNotHasKey('authorization', $this->transport->requests[0]->headers);
    }

    public function test_does_not_override_an_existing_authorization_header(): void
    {
        $this->transport->queueResponse(new HttpResponse(200));

        $this->executor()->send(HttpRequest::get('https://api.envai.dev/up', ['Authorization' => 'Bearer custom']));

        $this->assertSame(['Bearer custom'], $this->transport->requests[0]->headers['authorization']);
    }

    public function test_injects_accept_and_user_agent_headers(): void
    {
        $this->transport->queueResponse(new HttpResponse(200));

        $this->executor()->send(HttpRequest::get('https://api.envai.dev/up'));

        $this->assertSame(['application/json'], $this->transport->requests[0]->headers['accept']);
        $this->assertMatchesRegularExpression(
            '#^envai-php/0\.1\.0 \(php/8\.\d+\.\d+[^)]*\)$#',
            $this->transport->requests[0]->headers['user-agent'][0],
        );
    }

    public function test_does_not_override_existing_user_agent(): void
    {
        $this->transport->queueResponse(new HttpResponse(200));

        $this->executor()->send(HttpRequest::get('https://api.envai.dev/up', ['User-Agent' => 'my-agent/1.0']));

        $this->assertSame(['my-agent/1.0'], $this->transport->requests[0]->headers['user-agent']);
    }

    public function test_api_request_unwraps_single_resource_envelope(): void
    {
        $this->transport->queueResponse(new HttpResponse(200, body: '{"data":{"id":"wsp_1","name":"alpha"}}'));

        $resource = $this->executor()->apiRequest('GET', '/workspaces/wsp_1', [], null, TestResource::class);

        $this->assertInstanceOf(TestResource::class, $resource);
        $this->assertSame('wsp_1', $resource->id);
        $this->assertSame('https://api.envai.dev/api/v1/workspaces/wsp_1', $this->transport->requests[0]->uri);
        $this->assertArrayNotHasKey('content-type', $this->transport->requests[0]->headers);
    }

    public function test_api_request_missing_envelope_throws_protocol_violation(): void
    {
        $this->transport->queueResponse(new HttpResponse(200, body: '{"id":"wsp_1"}'));

        $this->expectException(ProtocolViolationException::class);

        $this->executor()->apiRequest('GET', '/workspaces/wsp_1', [], null, TestResource::class);
    }

    public function test_api_request_decodes_collection(): void
    {
        $this->transport->queueResponse(new HttpResponse(200, body: '{"data":[{"id":"r1"},{"id":"r2","name":"beta"}]}'));

        $collection = $this->executor()->apiRequest('GET', '/tools', [], null, TestResource::class, EnvelopeMode::Collection);

        $this->assertInstanceOf(ResourceCollection::class, $collection);
        $this->assertCount(2, $collection->items);
        $this->assertSame('beta', $collection->items[1]->name);
    }

    public function test_api_request_decodes_top_level_acknowledgment(): void
    {
        $this->transport->queueResponse(new HttpResponse(200, body: '{"status":"accepted","turn_id":"cmpl_1"}'));

        $ack = $this->executor()->apiRequest('POST', '/chats/cht_1/messages', [], null, TestAck::class, EnvelopeMode::TopLevel);

        $this->assertInstanceOf(TestAck::class, $ack);
        $this->assertSame('accepted', $ack->status);
        $this->assertSame('cmpl_1', $ack->turnId);
    }

    public function test_api_request_sets_content_type_for_json_bodies(): void
    {
        $this->transport->queueResponse(new HttpResponse(200, body: '{"status":"accepted"}'));

        $this->executor()->apiRequest('POST', '/chats/cht_1/messages', [], ['message' => 'hi'], TestAck::class, EnvelopeMode::TopLevel);

        $this->assertSame(['application/json'], $this->transport->requests[0]->headers['content-type']);
        $this->assertSame('{"message":"hi"}', $this->transport->requests[0]->body);
    }

    public function test_api_request_maps_error_status_to_exception(): void
    {
        $this->transport->queueResponse(new HttpResponse(
            404,
            body: '{"error":{"type":"not_found","message":"The chat was not found."}}',
        ));

        try {
            $this->executor()->apiRequest('GET', '/chats/cht_1', [], null, TestResource::class);
            $this->fail('Expected NotFoundException.');
        } catch (NotFoundException $e) {
            $this->assertSame(404, $e->httpStatus);
            $this->assertSame('The chat was not found.', $e->getMessage());
        }
    }

    public function test_safe_request_is_retried_on_503_and_succeeds(): void
    {
        $this->transport->queueResponse(new HttpResponse(503, body: '{"error":{"type":"server_error","message":"Busy."}}'));
        $this->transport->queueResponse(new HttpResponse(200, body: '{"data":{}}'));

        $response = $this->executor()->send(HttpRequest::get('https://api.envai.dev/up'));

        $this->assertSame(200, $response->status);
        $this->assertCount(2, $this->transport->requests);
        $this->assertSame(0, $this->transport->queueCount());
    }

    public function test_never_retry_request_is_not_retried_on_503(): void
    {
        $this->transport->queueResponse(new HttpResponse(503, body: '{"error":{"type":"server_error","message":"Busy."}}'));

        $request = HttpRequest::post('https://api.envai.dev/chats/cht_1/messages')
            ->withRetrySafety(RetrySafety::NeverRetry);

        $response = $this->executor()->send($request);

        $this->assertSame(503, $response->status);
        $this->assertCount(1, $this->transport->requests);
    }

    public function test_safe_request_is_not_retried_on_404(): void
    {
        $this->transport->queueResponse(new HttpResponse(404, body: '{"error":{"type":"not_found","message":"Gone."}}'));

        $response = $this->executor()->send(HttpRequest::get('https://api.envai.dev/up'));

        $this->assertSame(404, $response->status);
        $this->assertCount(1, $this->transport->requests);
    }

    public function test_null_retry_policy_disables_retries(): void
    {
        $this->transport->queueResponse(new HttpResponse(503, body: '{"error":{"type":"server_error","message":"Busy."}}'));

        $executor = new RequestExecutor(
            $this->transport,
            new UriResolver('https://api.envai.dev'),
            retryPolicy: null,
            apiKey: new SensitiveString(self::API_KEY),
        );

        $response = $executor->send(HttpRequest::get('https://api.envai.dev/up'));

        $this->assertSame(503, $response->status);
        $this->assertCount(1, $this->transport->requests);
    }

    public function test_exhausted_retry_attempts_return_last_status_which_is_mapped(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 0, jitter: false);

        $this->transport->queueResponse(new HttpResponse(503, body: '{"error":{"type":"server_error","message":"Busy 1."}}'));
        $this->transport->queueResponse(new HttpResponse(503, body: '{"error":{"type":"server_error","message":"Busy 2."}}'));
        $this->transport->queueResponse(new HttpResponse(503, body: '{"error":{"type":"server_error","message":"Busy 3."}}'));

        $executor = $this->executor($policy);

        try {
            $executor->apiRequest('GET', '/up', [], null, TestAck::class, EnvelopeMode::TopLevel);
            $this->fail('Expected ServerException.');
        } catch (ServerException $e) {
            $this->assertSame(503, $e->httpStatus);
            $this->assertSame('Busy 3.', $e->getMessage());
        }

        $this->assertCount(3, $this->transport->requests);
    }

    public function test_connect_phase_transport_error_is_retried_then_succeeds(): void
    {
        $this->transport->queueException(new TransportException('Connection refused by the remote host.'));
        $this->transport->queueResponse(new HttpResponse(200, body: '{"data":{}}'));

        $response = $this->executor()->send(HttpRequest::get('https://api.envai.dev/up'));

        $this->assertSame(200, $response->status);
        $this->assertCount(2, $this->transport->requests);
    }

    public function test_response_phase_transport_error_is_not_retried(): void
    {
        $this->transport->queueException(new TransportException('Failed to write the response chunk to the target resource.'));

        $this->expectException(TransportException::class);

        try {
            $this->executor()->send(HttpRequest::get('https://api.envai.dev/up'));
        } catch (TransportException $e) {
            $this->assertCount(1, $this->transport->requests);

            throw $e;
        }
    }

    public function test_never_retry_request_transport_error_is_not_retried(): void
    {
        $this->transport->queueException(new TransportException('Connection refused by the remote host.'));

        try {
            $this->executor()->send(HttpRequest::get('https://api.envai.dev/up')->withRetrySafety(RetrySafety::NeverRetry));
            $this->fail('Expected TransportException.');
        } catch (TransportException $e) {
            $this->assertCount(1, $this->transport->requests);
            $this->assertSame('Connection refused by the remote host.', $e->getMessage());
        }
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function transportFailureClassificationProvider(): iterable
    {
        yield 'connection refused' => ['Connection refused by the remote host.', true];
        yield 'failed to connect' => ['Failed to connect to api.envai.dev port 443: Connection refused.', true];
        yield 'dns resolution' => ['Could not resolve host: api.envai.dev', true];
        yield 'tls handshake' => ['SSL certificate verify failed', true];
        yield 'connect timeout' => ['Connection timed out after 5000 ms', true];
        yield 'sink write failure' => ['Failed to write the response chunk to the target resource.', false];
        yield 'premature eof' => ['Premature EOF while reading response body', false];
        yield 'truncated transfer' => ['Transfer closed with outstanding read data remaining', false];
        yield 'generic curl timeout' => ['curl error 28: Operation timed out after 60000 ms with 0 bytes received', false];
        yield 'unknown message' => ['Unexpected network condition', false];
    }

    #[DataProvider('transportFailureClassificationProvider')]
    public function test_classify_transport_failure(string $message, bool $expected): void
    {
        $this->assertSame($expected, RequestExecutor::classifyTransportFailure(new TransportException($message)));
    }

    public function test_logger_receives_debug_entries_without_credentials(): void
    {
        $this->transport->queueResponse(new HttpResponse(503, body: '{"error":{"type":"server_error","message":"Busy."}}'));
        $this->transport->queueResponse(new HttpResponse(200, body: '{"data":{}}'));

        $this->executor()->send(HttpRequest::get('https://api.envai.dev/up'));

        $this->assertNotEmpty($this->logger->records);

        foreach ($this->logger->records as $record) {
            $this->assertSame('debug', $record['level']);
            $this->assertStringNotContainsString(self::API_KEY, $record['message']);
            $this->assertStringNotContainsStringIgnoringCase('authorization', $record['message']);
            $this->assertSame([], $record['context']);
        }
    }

    public function test_logger_redacts_query_parameters_from_signed_artifact_uris(): void
    {
        $this->transport->queueResponse(new HttpResponse(200));

        $uri = 'https://api.envai.dev/artifacts/download?path=generated%2F01ARZ3NDEK%2Fout.jpeg&signature=6f8d2c4a9b1e5f7a&expires=1786320000';

        $this->executor()->send(HttpRequest::get($uri));

        $this->assertNotEmpty($this->logger->records);

        foreach ($this->logger->records as $record) {
            $this->assertStringNotContainsString('path=generated', $record['message']);
            $this->assertStringNotContainsString('signature=6f8d2c4a9b1e5f7a', $record['message']);
            $this->assertStringNotContainsString('expires=1786320000', $record['message']);
            $this->assertStringContainsString('https://api.envai.dev/artifacts/download', $record['message']);
        }
    }

    public function test_logger_redacts_callback_token_from_provider_webhook_uris(): void
    {
        $this->transport->queueResponse(new HttpResponse(200));

        $this->executor()->send(HttpRequest::post('https://api.envai.dev/webhooks/providers/replicate?callback_token=0192f3a4-6b1c'));

        $this->assertNotEmpty($this->logger->records);

        foreach ($this->logger->records as $record) {
            $this->assertStringNotContainsString('callback_token', $record['message']);
            $this->assertStringNotContainsString('0192f3a4-6b1c', $record['message']);
            $this->assertStringContainsString('https://api.envai.dev/webhooks/providers/replicate', $record['message']);
        }
    }

    public function test_logger_redacts_userinfo_from_request_uris(): void
    {
        $this->transport->queueResponse(new HttpResponse(200));

        $this->executor()->send(HttpRequest::get('https://svc:secret-value@api.envai.dev/up'));

        $this->assertNotEmpty($this->logger->records);

        foreach ($this->logger->records as $record) {
            $this->assertStringNotContainsString('secret-value', $record['message']);
            $this->assertStringNotContainsString('svc:secret-value@', $record['message']);
            $this->assertStringContainsString('https://api.envai.dev/up', $record['message']);
        }
    }

    public function test_retry_after_takes_precedence_over_computed_delay(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 100, maxDelayMs: 5000, jitter: false);

        $this->transport->queueResponse(new HttpResponse(429, headers: ['Retry-After' => ['1']], body: '{"error":{"type":"too_many_requests","message":"Slow."}}'));
        $this->transport->queueResponse(new HttpResponse(503));
        $this->transport->queueResponse(new HttpResponse(200, body: '{"data":{}}'));

        $sleeps = [];
        $sleepMicroseconds = function (int $microseconds) use (&$sleeps): void {
            $sleeps[] = [count($this->transport->requests), $microseconds];
        };
        $response = $this->executor($policy, sleepMicroseconds: $sleepMicroseconds)->send(HttpRequest::get('https://api.envai.dev/up'));

        $this->assertSame(200, $response->status);
        $this->assertCount(3, $this->transport->requests);
        $this->assertSame([[1, 1_000_000], [2, 400_000]], $sleeps);
        $this->assertSame(0, $this->transport->queueCount());
    }

    public function test_retry_after_is_ignored_when_policy_does_not_respect_it(): void
    {
        $policy = new RetryPolicy(maxAttempts: 3, baseDelayMs: 50, maxDelayMs: 10_000, jitter: false, respectRetryAfter: false);

        $this->transport->queueResponse(new HttpResponse(429, headers: ['Retry-After' => ['60']], body: '{"error":{"type":"too_many_requests","message":"Slow."}}'));
        $this->transport->queueResponse(new HttpResponse(429, headers: ['Retry-After' => ['60']]));
        $this->transport->queueResponse(new HttpResponse(200, body: '{"data":{}}'));

        $sleeps = [];
        $sleepMicroseconds = function (int $microseconds) use (&$sleeps): void {
            $sleeps[] = [count($this->transport->requests), $microseconds];
        };
        $response = $this->executor($policy, sleepMicroseconds: $sleepMicroseconds)->send(HttpRequest::get('https://api.envai.dev/up'));

        $this->assertSame(200, $response->status);
        $this->assertCount(3, $this->transport->requests);
        $this->assertSame([[1, 100_000], [2, 200_000]], $sleeps);
        $this->assertSame(0, $this->transport->queueCount());
    }

    public function test_connect_phase_retry_uses_the_same_deterministic_backoff(): void
    {
        $this->transport->queueException(new TransportException('Connection refused.'));
        $this->transport->queueResponse(new HttpResponse(200));
        $sleeps = [];
        $sleepMicroseconds = function (int $microseconds) use (&$sleeps): void {
            $sleeps[] = [count($this->transport->requests), $microseconds];
        };

        $response = $this->executor(new RetryPolicy(baseDelayMs: 25, jitter: false), sleepMicroseconds: $sleepMicroseconds)->send(HttpRequest::get('https://api.envai.dev/up'));

        $this->assertSame(200, $response->status);
        $this->assertSame([[1, 50_000]], $sleeps);
        $this->assertCount(2, $this->transport->requests);
    }

    public function test_zero_backoff_does_not_call_the_sleeper(): void
    {
        $this->transport->queueResponse(new HttpResponse(503));
        $this->transport->queueResponse(new HttpResponse(200));
        $response = $this->executor(sleepMicroseconds: static function (int $microseconds): void {
            self::fail('A zero backoff must not sleep.');
        })->send(HttpRequest::get('https://api.envai.dev/up'));

        $this->assertSame(200, $response->status);
        $this->assertCount(2, $this->transport->requests);
    }

    public function test_default_sleeper_supports_a_positive_retry_backoff(): void
    {
        $this->transport->queueResponse(new HttpResponse(503));
        $this->transport->queueResponse(new HttpResponse(200));

        $response = $this->executor(new RetryPolicy(baseDelayMs: 1, jitter: false))->send(HttpRequest::get('https://api.envai.dev/up'));

        $this->assertSame(200, $response->status);
        $this->assertCount(2, $this->transport->requests);
    }

    public function test_send_streaming_returns_download_metadata(): void
    {
        $this->transport->queueResponse(new HttpResponse(
            200,
            headers: [
                'Content-Type' => ['application/pdf; charset=binary'],
                'Content-Disposition' => ['attachment; filename="report.pdf"'],
                'Content-Length' => ['1234'],
            ],
        ));

        $result = $this->executor()->sendStreaming(HttpRequest::get('https://api.envai.dev/download'));

        $this->assertSame('application/pdf', $result->mediaType);
        $this->assertSame('report.pdf', $result->filename);
        $this->assertSame(1234, $result->contentLength);
        $this->assertSame('Bearer '.self::API_KEY, $this->transport->requests[0]->headers['authorization'][0]);
    }

    public function test_send_streaming_parses_rfc5987_filename(): void
    {
        $this->transport->queueResponse(new HttpResponse(
            200,
            headers: ['Content-Disposition' => ["attachment; filename*=UTF-8''caf%C3%A9%20guide.pdf"]],
        ));

        $result = $this->executor()->sendStreaming(HttpRequest::get('https://api.envai.dev/download'));

        $this->assertSame('café guide.pdf', $result->filename);
    }

    public function test_send_streaming_defaults_media_type_without_content_type(): void
    {
        $this->transport->queueResponse(new HttpResponse(200));

        $result = $this->executor()->sendStreaming(HttpRequest::get('https://api.envai.dev/download'));

        $this->assertSame('application/octet-stream', $result->mediaType);
        $this->assertNull($result->filename);
        $this->assertNull($result->contentLength);
    }

    public function test_send_streaming_maps_error_status_to_exception(): void
    {
        $this->transport->queueResponse(new HttpResponse(
            503,
            body: '{"error":{"type":"server_error","message":"Busy."}}',
        ));

        $this->expectException(ServerException::class);

        $this->executor()->sendStreaming(HttpRequest::get('https://api.envai.dev/download'));
    }
}
