<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Background;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Client\Internal\Poller;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\RetryPolicy;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\EnvaiException;
use Digitalygo\Envai\Exceptions\OperationCancelledException;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Exceptions\ServerException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Http\CancellationSource;
use Digitalygo\Envai\Http\Client\ErrorMapper;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Streaming\Atp\ChatEventStream;
use Digitalygo\Envai\Tests\Streaming\Atp\FakeEventStreamConnection;
use Digitalygo\Envai\Tests\Streaming\Fixtures;
use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tests\Tools\Support\RunnerHarness;
use Digitalygo\Envai\Tests\Wiki\ContractNegotiationTest;
use Digitalygo\Envai\Tests\Wiki\ContractTestTransport;
use Digitalygo\Envai\Tests\Wiki\WikiContractTest;
use Digitalygo\Envai\Tools\State\FileClientToolCallStore;
use Digitalygo\Envai\Tools\State\ToolCallIdentity;
use Digitalygo\Envai\Tools\State\ToolCallStatus;
use PHPUnit\Framework\TestCase;

final class ParityTest extends TestCase
{
    private function connection(array $events): FakeEventStreamConnection
    {
        return new FakeEventStreamConnection(array_map(static fn (array $event): string => 'data: '.json_encode($event, JSON_THROW_ON_ERROR)."\n\n", $events), responseHeaders: ['content-type' => ['text/event-stream'], 'x-envai-contract-version' => ['3']]);
    }

    public function test_public_helper_replay_barriers_precede_real_signed_tool_consumption(): void
    {
        foreach (['result', 'completed', 'failed', 'cancelled', 'pending'] as $case) {
            $tool = new FlexibleTool(return: 'ok');
            $harness = new RunnerHarness(['echo' => FlexibleTool::class], [FlexibleTool::class => $tool]);
            $signed = $harness->event('call_replay', 'echo', [], 'turn');
            $request = Fixtures::envelope(1, 'request', '2026-10-31T00:00:00Z', 'agent.tool.request', [
                'call_id' => $signed->callId, 'tool_name' => $signed->toolName, 'kind' => 'client', 'args' => $signed->args,
                'signature' => ['alg' => $signed->signature->alg, 'kid' => $signed->signature->kid, 'sig' => $signed->signature->sig],
            ], Fixtures::orchestrator(), 'turn');
            $events = [$request];
            $seq = 2;
            if ($case === 'result') {
                $events[] = Fixtures::envelope($seq++, 'result', '2026-10-31T00:00:00Z', 'agent.tool.result', ['call_id' => 'call_replay', 'output' => 'already resolved', 'is_error' => false], Fixtures::orchestrator(), 'turn');
            } elseif ($case !== 'pending') {
                $terminal = Fixtures::sample('turn.'.$case);
                $terminal['seq'] = $seq++;
                $terminal['turn_id'] = 'turn';
                $terminal['data']['turn_id'] = 'turn';
                $events[] = $terminal;
            }
            $events[] = Fixtures::ping();
            $terminal = Fixtures::sample('turn.completed');
            $terminal['seq'] = $seq;
            $terminal['turn_id'] = 'turn';
            $terminal['data']['turn_id'] = 'turn';
            $events[] = $terminal;
            foreach ($events as &$event) {
                $event['chat_id'] = 'cht_test_1';
            }
            unset($event);
            $transport = new ContractTestTransport;
            $transport->connections[] = $this->connection($events);
            $transport->responses = [new HttpResponse(200, ['x-envai-contract-version' => '3'], json_encode(ContractNegotiationTest::capabilities())), new HttpResponse(202, ['x-envai-contract-version' => '3'], json_encode(['turn_id' => 'turn', 'status' => 'accepted']))];
            $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('test')), $transport);
            $harness->runner->handleEvents($client->chat('w', 'cht_test_1')->sendAndStream('message', idempotencyKey: 'stable'), 'cht_test_1');
            self::assertSame($case === 'pending' ? 1 : 0, $tool->invocations, $case);
            self::assertCount(2, $transport->requests);
            self::assertSame(['stable'], $transport->requests[1]->headers['idempotency-key']);
        }
    }

    public function test_eof_reconnect_resumes_cursor_and_has_finite_budget(): void
    {
        $first = Fixtures::sample('turn.started');
        $first['seq'] = 1;
        $second = Fixtures::sample('turn.completed');
        $second['seq'] = 2;
        $cursors = [];
        $stream = new ChatEventStream($this->connection([$first]), connectionFactory: function (int $cursor) use (&$cursors, $first, $second) {
            $cursors[] = $cursor;

            return $this->connection(count($cursors) === 1 ? [$first, $second] : []);
        }, maxReconnectAttempts: 2, reconnectBaseDelayMs: 0, jitterMs: 0, reconnectOnDisconnect: true);
        $seen = [];
        try {
            foreach ($stream as $event) {
                $seen[] = $event->envelope->seq;
            }
            self::fail('Budget exhaustion was silently treated as completion.');
        } catch (TransportException $exception) {
            self::assertStringContainsString('budget exhausted', $exception->getMessage());
        }
        self::assertSame([1, 2], $seen);
        self::assertSame([1, 2], $cursors);
    }

    public function test_custom_headers_reach_http_stream_and_preflight_but_not_public_requests(): void
    {
        $transport = new ContractTestTransport;
        $transport->responses[] = new HttpResponse(200, [], '{"data":[]}');
        $transport->responses[] = new HttpResponse(200, [], '{"keys":[]}');
        $transport->responses[] = new HttpResponse(200, ['x-envai-contract-version' => '3'], json_encode(ContractNegotiationTest::capabilities()));
        $transport->responses[] = new HttpResponse(202, ['x-envai-contract-version' => '3'], '{"turn_id":"t","status":"accepted"}');
        $transport->connections[] = $this->connection([]);
        $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('test'), headers: ['X-Tenant' => 'tenant']), $transport);
        $client->workspaces()->list();
        $client->capabilities()->jwks();
        $client->chat('w', 'chat')->sendMessage('message');
        $client->chat('w', 'chat')->events()->close();
        foreach ([0, 2, 3] as $i) {
            self::assertSame(['tenant'], $transport->requests[$i]->headers['x-tenant']);
            self::assertSame(['Bearer test'], $transport->requests[$i]->headers['authorization']);
        }
        self::assertArrayNotHasKey('x-tenant', $transport->requests[1]->headers);
        self::assertArrayNotHasKey('authorization', $transport->requests[1]->headers);
        self::assertSame(['tenant'], $transport->streamRequests[0]->headers['x-tenant']);
    }

    public function test_completion_iterator_requests_second_page_without_following_untrusted_links(): void
    {
        $transport = new ContractTestTransport;
        foreach ([1, 2] as $number) {
            $transport->responses[] = new HttpResponse(200, ['x-envai-contract-version' => '3'], json_encode(['data' => [], 'links' => ['first' => null, 'last' => null, 'prev' => null, 'next' => 'https://untrusted.invalid/'], 'meta' => ['current_page' => $number, 'from' => null, 'last_page' => 2, 'per_page' => 1, 'to' => null, 'total' => 0]]));
        }
        $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('test')), $transport);
        self::assertSame([], iterator_to_array($client->completions()->iterate('w', 'c')));
        self::assertCount(2, $transport->requests);
        self::assertSame('https://example.test/api/v1/workspaces/w/chats/c/completions?page=2', $transport->requests[1]->uri);
    }

    public function test_wiki_and_image_waiters_only_read_and_preserve_explicit_terminal_states(): void
    {
        $transport = new ContractTestTransport;
        $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('test')), $transport);
        $source = WikiContractTest::fixture('source-accepted')['data'];
        $source['conversion']['status'] = 'completed';
        $source['lifecycle'] = 'inbox';
        $source['indexing']['status'] = 'pending';
        $transport->responses[] = new HttpResponse(200, ['x-envai-contract-version' => '3'], json_encode(['data' => $source]));
        self::assertSame('pending', $client->workspaceFiles()->waitForSource('w', 'f', intervalMilliseconds: 1)->indexing->status);
        $operation = WikiContractTest::fixture('operation-completed')['data'];
        $operation['status'] = 'failed';
        $transport->responses[] = new HttpResponse(200, ['x-envai-contract-version' => '3'], json_encode(['data' => $operation]));
        self::assertSame('failed', $client->wiki()->waitForOperation('w', 'o', intervalMilliseconds: 1)->status);
        $base = ['id' => 'image', 'status' => 'cancelled', 'provider' => 'replicate', 'initial_provider' => 'replicate', 'routed' => false, 'model' => 'fixture/model', 'created_at' => '2026-10-12T20:00:00Z'];
        $transport->responses[] = new HttpResponse(200, [], json_encode(['data' => $base]));
        self::assertSame('cancelled', $client->images()->waitForGeneration('image', intervalMilliseconds: 1)->status->value);
        self::assertSame(['GET', 'GET', 'GET'], array_column($transport->requests, 'method'));
    }

    public function test_file_store_replay_tombstones_survive_recreation_and_states_never_downgrade(): void
    {
        $path = sys_get_temp_dir().'/envai-parity-'.bin2hex(random_bytes(8));
        try {
            $store = new FileClientToolCallStore($path);
            foreach (['call', 'turn', 'task'] as $kind) {
                $store->markTerminal('chat', $kind, 'id');
                self::assertTrue((new FileClientToolCallStore($path))->isTerminal('chat', $kind, 'id'));
                self::assertFalse($store->isTerminal('other', $kind, 'id'));
            }
            $identity = new ToolCallIdentity('chat', 'call', 'echo');
            $store->begin($identity);
            $store->markResolved($identity);
            $store->markSubmitted($identity);
            $store->markAmbiguous($identity);
            self::assertSame(ToolCallStatus::AlreadyResolved, $store->begin($identity)->status);
        } finally {
            foreach (glob($path.'*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    public function test_public_image_waiter_cancellation_deadline_and_error_propagation(): void
    {
        foreach (['cancel', 'deadline', 'error'] as $case) {
            $transport = new ContractTestTransport;
            $token = new CancellationSource;
            $transport->onSend = static function () use ($case, $token): void {
                if ($case === 'cancel') {
                    $token->cancel();
                } elseif ($case === 'deadline') {
                    usleep(5000);
                }
            };
            $base = ['id' => 'image', 'status' => 'succeeded', 'provider' => 'replicate', 'initial_provider' => 'replicate', 'routed' => false, 'model' => 'fixture/model', 'created_at' => '2026-10-12T20:00:00Z'];
            $transport->responses[] = $case === 'error' ? new HttpResponse(503, ['X-Request-ID' => 'poll-failure'], '{"error":{"type":"server_error","message":"unavailable"}}') : new HttpResponse(200, [], json_encode(['data' => $base]));
            $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('test'), retryPolicy: new RetryPolicy), $transport);
            try {
                $client->images()->waitForGeneration('image', timeoutSeconds: $case === 'deadline' ? 0.001 : 1, intervalMilliseconds: 1, token: $token);
                self::fail('Late result or server error was accepted.');
            } catch (EnvaiException $error) {
                self::assertInstanceOf(match ($case) {
                    'cancel' => OperationCancelledException::class,
                    'deadline' => TransportException::class,
                    'error' => ServerException::class,
                }, $error);
                if ($case === 'error') {
                    self::assertSame('poll-failure', $error->requestId());
                }
            }
            self::assertCount(1, $transport->requests);
            self::assertSame('GET', $transport->requests[0]->method);
            self::assertSame(1, $transport->requests[0]->requestTimeoutSeconds);
        }
    }

    public function test_poll_cancellation_after_read_wins_over_terminal_result(): void
    {
        $token = new CancellationSource;
        $this->expectException(OperationCancelledException::class);
        Poller::wait(function () use ($token) {
            $token->cancel();

            return 'terminal';
        }, static fn (): bool => true, 1, 1, $token);
    }

    public function test_poll_deadline_after_read_wins_over_terminal_result(): void
    {
        $this->expectException(TransportException::class);
        Poller::wait(function () {
            usleep(3000);

            return 'terminal';
        }, static fn (): bool => true, 0.001, 1, null);
    }

    public function test_foreign_chat_rejected_before_cursor_advance(): void
    {
        $frame = Fixtures::sample('turn.started');
        $frame['seq'] = 1;
        $stream = new ChatEventStream($this->connection([$frame]), chatId: 'other');
        try {
            iterator_to_array($stream);
            self::fail('Foreign event accepted.');
        } catch (ProtocolViolationException) {
            self::assertSame(0, $stream->lastCursor());
        }
    }

    public function test_error_request_id_is_populated_case_insensitively(): void
    {
        self::assertSame('trace-123', ErrorMapper::fromStatusAndBody(503, null, ['X-Request-Id' => ['trace-123']])->requestId());
    }

    public function test_reserved_config_headers_are_rejected(): void
    {
        foreach (['Authorization', 'x-ENvai-extensions', 'Host', 'Idempotency-Key', 'Content-Type'] as $name) {
            try {
                new EnvaiConfig('https://example.test', new SensitiveString('test'), headers: [$name => 'bad']);
                self::fail('Reserved header accepted.');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('controlled', $exception->getMessage());
            }
        }
    }
}
