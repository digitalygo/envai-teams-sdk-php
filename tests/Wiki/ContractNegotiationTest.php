<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Wiki;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Client\OperationInventory;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\ContractCompatibilityException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Http\Client\ContractTransport;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\StreamRequest;
use Digitalygo\Envai\Models\Chat\ChatMessageRequest;
use Digitalygo\Envai\Models\Wiki\WikiRunRequest;
use Digitalygo\Envai\Tests\Streaming\Atp\FakeEventStreamConnection;
use Digitalygo\Envai\Tests\Streaming\Fixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContractNegotiationTest extends TestCase
{
    private function client(ContractTestTransport $transport, int $version = 3, string $base = 'https://envai.example'): EnvaiClient
    {
        return new EnvaiClient(new EnvaiConfig($base, new SensitiveString('test'), contractVersion: $version), $transport);
    }

    public static function capabilities(): array
    {
        return ['protocol_version' => 3, 'contract_version' => 3, 'supported_contract_versions' => [2, 3], 'legacy_v2_mode' => 'historical_reads_replay_only', 'families' => [], 'event_types' => [], 'tool_kinds' => [], 'features' => []];
    }

    public static function response(array $data, int $version = 3, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, ['X-Envai-Contract-Version' => (string) $version], json_encode($data));
    }

    public function test_default_three_preflights_once_and_uses_safe_namespace_with_api_prefix(): void
    {
        $transport = new ContractTestTransport;
        $transport->responses = [self::response(self::capabilities()), self::response(['status' => 'accepted', 'turn_id' => Fixtures::TURN_ID], status: 202), self::response(['status' => 'accepted', 'turn_id' => Fixtures::TURN_ID], status: 202)];
        $client = $this->client($transport, base: 'https://envai.example/api/v1');
        self::assertSame(3, $client->config()->contractVersion);
        $client->chats()->sendMessage('c/a', new ChatMessageRequest('hello'));
        $client->chats()->cancel('c/a');
        self::assertSame(['GET', 'POST', 'POST'], array_column($transport->requests, 'method'));
        self::assertSame('https://envai.example/api/v1/atp/v3/capabilities', $transport->requests[0]->uri);
        self::assertSame('https://envai.example/api/v1/atp/v3/chats/c%2Fa/messages', $transport->requests[1]->uri);
        foreach ($transport->requests as $request) {
            self::assertSame(['3'], $request->headers['x-envai-contract-version']);
            self::assertSame(['Bearer test'], $request->headers['authorization']);
        }
    }

    public static function failedPreflights(): iterable
    {
        yield 'old server' => [new HttpResponse(404, [], '{}')];
        yield 'no ack' => [new HttpResponse(200, [], json_encode(self::capabilities()))];
        yield 'wrong ack' => [self::response(self::capabilities(), 2)];
        yield 'duplicate ack' => [new HttpResponse(200, ['X-Envai-Contract-Version' => ['3', '3']], json_encode(self::capabilities()))];
        yield 'old protocol' => [self::response([...self::capabilities(), 'protocol_version' => 2])];
        yield 'old contract' => [self::response([...self::capabilities(), 'contract_version' => 2])];
        yield 'no support' => [self::response([...self::capabilities(), 'supported_contract_versions' => [2]])];
        yield 'malformed support' => [self::response([...self::capabilities(), 'supported_contract_versions' => '3'])];
        yield 'object support' => [self::response([...self::capabilities(), 'supported_contract_versions' => ['legacy' => 2, 'current' => 3]])];
        yield 'non-integer support' => [self::response([...self::capabilities(), 'supported_contract_versions' => ['2', 3]])];
        yield 'malformed body' => [new HttpResponse(200, ['X-Envai-Contract-Version' => '3'], 'invalid')];
        yield 'server unavailable' => [new HttpResponse(503, [], '{}')];
    }

    #[DataProvider('failedPreflights')]
    public function test_preflight_rejection_sends_no_mutation(HttpResponse $response): void
    {
        $transport = new ContractTestTransport;
        $transport->responses = [$response];
        try {
            $this->client($transport)->chats()->sendMessage('chat', new ChatMessageRequest('hello'));
            self::fail('Compatibility failure accepted.');
        } catch (ContractCompatibilityException) {
            self::assertCount(1, $transport->requests);
            self::assertSame('GET', $transport->requests[0]->method);
        }
    }

    public function test_mixed_server_route_failure_and_ambiguous_ack_never_resubmit(): void
    {
        foreach ([new HttpResponse(404, [], '{}'), new HttpResponse(202, [], '{"status":"accepted","turn_id":"turn"}')] as $response) {
            $transport = new ContractTestTransport;
            $transport->responses = [self::response(self::capabilities()), $response];
            try {
                $this->client($transport)->chats()->sendMessage('chat', new ChatMessageRequest('hello'));
                self::fail('Unacknowledged response accepted.');
            } catch (ContractCompatibilityException) {
                self::assertCount(2, $transport->requests);
                self::assertStringContainsString('/atp/v3/chats/', $transport->requests[1]->uri);
            }
        }
    }

    public function test_wiki_requires_three_and_acknowledgment_before_hydration(): void
    {
        $transport = new ContractTestTransport;
        $transport->responses = [self::response(self::capabilities()), self::response(['data' => WikiContractTest::fixture('operation-completed')['data']], status: 202)];
        $this->client($transport)->wiki()->inbox('w', new WikiRunRequest('https://hooks.example/wiki'), 'idempotency');
        self::assertSame(['3'], $transport->requests[1]->headers['x-envai-contract-version']);
        self::assertSame(['idempotency'], $transport->requests[1]->headers['idempotency-key']);
        $transport->responses = [new HttpResponse(200, [], 'not even valid JSON')];
        $this->expectException(ContractCompatibilityException::class);
        $this->client($transport)->wiki()->categories('w');
    }

    public function test_legacy_is_uniformly_readonly_while_public_jwks_remains_unnegotiated(): void
    {
        $transport = new ContractTestTransport;
        $client = $this->client($transport, 2);
        foreach ([fn () => $client->chats()->sendMessage('c', new ChatMessageRequest('x')), fn () => $client->wiki()->categories('w')] as $call) {
            try {
                $call();
                self::fail('Legacy mutation allowed.');
            } catch (ContractCompatibilityException) {
                self::assertCount(0, $transport->requests);
            }
        }
        $transport->responses = [new HttpResponse(200, [], '{"keys":[]}')];
        $client->capabilities()->wikiWebhookJwks();
        self::assertArrayNotHasKey('x-envai-contract-version', $transport->requests[0]->headers);
        self::assertStringContainsString('/.well-known/', $transport->requests[0]->uri);
    }

    public function test_legacy_completion_pages_are_validated_atomically(): void
    {
        foreach ([['cost_usd' => null], ['cost_usd' => '0'], []] as $bad) {
            $transport = new ContractTestTransport;
            $transport->responses = [self::response(['data' => [['cost_usd' => 0], $bad]], 2)];
            $guard = new ContractTransport($transport, new UriResolver('https://envai.example'), 2);
            try {
                $guard->send(HttpRequest::get('https://envai.example/api/v1/workspaces/w/chats/c/completions'));
                self::fail('Invalid legacy page accepted.');
            } catch (ContractCompatibilityException) {
                self::assertCount(1, $transport->requests);
            }
        }
        foreach ([['cost_usd' => 0], [['cost_usd' => 0.25]], []] as $valid) {
            $transport = new ContractTestTransport;
            $transport->responses = [self::response(['data' => $valid], 2)];
            $guard = new ContractTransport($transport, new UriResolver('https://envai.example'), 2);
            self::assertSame(200, $guard->send(HttpRequest::get('https://envai.example/api/v1/workspaces/w/chats/c/completions'))->status);
        }
    }

    public function test_v3_completion_preserves_unknown_without_legacy_coercion(): void
    {
        $transport = new ContractTestTransport;
        $wire = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/control-plane-http/completion-legacy.json'), true)['data'];
        $wire['cost_usd'] = null;
        $transport->responses = [self::response(['data' => $wire])];
        self::assertNull($this->client($transport)->completions()->get('w', 'c', $wire['id'])->costUsd);
        self::assertSame(['3'], $transport->requests[0]->headers['x-envai-contract-version']);
    }

    public function test_stream_ack_failure_closes_connection_and_never_submits(): void
    {
        foreach ([[], ['x-envai-contract-version' => ['2']], ['x-envai-contract-version' => ['3', '3']]] as $headers) {
            $transport = new ContractTestTransport;
            $connection = new FakeEventStreamConnection([], responseHeaders: $headers);
            $transport->connections = [$connection];
            try {
                $this->client($transport)->chat('w', 'c')->sendAndWait('hello');
                self::fail('Unacknowledged stream accepted.');
            } catch (ContractCompatibilityException) {
                self::assertTrue($connection->isClosed());
                self::assertSame([], $transport->requests);
            }
        }
    }

    public function test_legacy_stream_uses_unversioned_route_and_numeric_v2(): void
    {
        $transport = new ContractTestTransport;
        $wire = ContractVersionParsingTest::sample('turn.completed');
        $wire['v'] = 2;
        $transport->connections = [new FakeEventStreamConnection(['data: '.json_encode($wire)."\n\n"], responseHeaders: ['x-envai-contract-version' => ['2']])];
        $stream = $this->client($transport, 2)->chat('w', $wire['chat_id'])->events($wire['seq'] - 1);
        $events = iterator_to_array($stream);
        self::assertSame(2, $events[0]->envelope->v);
        self::assertStringNotContainsString('/atp/v3/', $transport->streamRequests[0]->uri);
        self::assertSame(['2'], $transport->streamRequests[0]->headers['x-envai-contract-version']);
    }

    public function test_contract_and_selector_validation_precedes_transport(): void
    {
        $transport = new ContractTestTransport;
        foreach ([1, 4] as $version) {
            try {
                new ContractTransport($transport, new UriResolver('https://envai.example'), $version);
                self::fail('Invalid version accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
            try {
                $this->client($transport, $version);
                self::fail('Invalid config accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
        $guard = new ContractTransport($transport, new UriResolver('https://envai.example'));
        $this->expectException(ContractCompatibilityException::class);
        $guard->send(HttpRequest::get('https://envai.example/api/v1/atp/v3/capabilities', ['X-Envai-Contract-Version' => ['2', '3']]));
    }

    public function test_upgrade_errors_and_native_stream_handshakes_are_explicit(): void
    {
        foreach ([400, 426] as $status) {
            $transport = new ContractTestTransport;
            $transport->responses = [new HttpResponse($status, [], '{"error":{"code":"contract_upgrade_required"}}')];
            try {
                $this->client($transport)->wiki()->categories('w');
                self::fail('Upgrade ignored.');
            } catch (ContractCompatibilityException) {
                self::assertTrue(true);
            }
        }
        $transport = new ContractTestTransport;
        $transport->connections = [new TransportException('old server', ['http_status' => 404])];
        $this->expectException(ContractCompatibilityException::class);
        $this->client($transport)->chats()->openEventStream('c');
    }

    public function test_every_inventory_mutation_and_non_get_is_denied_in_legacy_mode(): void
    {
        $transport = new ContractTestTransport;
        $guard = new ContractTransport($transport, new UriResolver('https://envai.example'), 2);
        $operations = array_values(OperationInventory::OPERATIONS);
        $operations[] = ['method' => 'HEAD', 'path' => '/up'];
        $operations[] = ['method' => 'PATCH', 'path' => '/unknown'];
        foreach ($operations as $operation) {
            if ($operation['method'] === 'GET') {
                continue;
            }
            try {
                $guard->send(new HttpRequest($operation['method'], 'https://envai.example/api/v1'.$operation['path']));
                self::fail('Legacy mutation escaped the guard: '.$operation['path']);
            } catch (ContractCompatibilityException) {
                self::assertSame([], $transport->requests);
            }
        }
    }

    public function test_explicit_legacy_operations_cannot_implicitly_select_three(): void
    {
        $transport = new ContractTestTransport;
        $modern = $this->client($transport);
        foreach ([fn () => $modern->capabilities()->getCapabilitiesLegacy(), fn () => $modern->chats()->openLegacyEventStream('c')] as $call) {
            try {
                $call();
                self::fail('Explicit legacy operation selected contract 3.');
            } catch (ContractCompatibilityException) {
                self::assertSame([], $transport->requests);
                self::assertSame([], $transport->streamRequests);
            }
        }
        $guard = new ContractTransport($transport, new UriResolver('https://envai.example'));
        foreach (['getCapabilitiesLegacy', 'streamChatEventsLegacy'] as $id) {
            $path = str_replace('{chat_id}', 'c', OperationInventory::OPERATIONS[$id]['path']);
            try {
                $guard->send(HttpRequest::get('https://envai.example/api/v1'.$path));
                self::fail('Inventory legacy route bypassed the guard.');
            } catch (ContractCompatibilityException) {
                self::assertSame([], $transport->requests);
            }
        }
        $legacy = $this->client($transport, 2);
        $transport->responses = [self::response(['protocol_version' => 2, 'families' => [], 'event_types' => [], 'tool_kinds' => [], 'features' => []], 2)];
        self::assertSame(2, $legacy->capabilities()->getCapabilitiesLegacy()->protocolVersion);
        self::assertSame(['2'], $transport->requests[0]->headers['x-envai-contract-version']);
        $transport->connections = [new FakeEventStreamConnection([], responseHeaders: ['x-envai-contract-version' => ['2']])];
        $legacy->chats()->openLegacyEventStream('c')->close();
        self::assertSame(['2'], $transport->streamRequests[0]->headers['x-envai-contract-version']);
    }

    public function test_mixed_historical_replay_preserves_versions_and_reconnect_selection(): void
    {
        $transport = new ContractTestTransport;
        $old = ContractVersionParsingTest::sample('turn.completed');
        $old['v'] = 2;
        $old['seq'] = 1;
        $new = $old;
        $new['v'] = 3;
        $new['seq'] = 2;
        $new['id'] = 'event-v3';
        $new['data']['cost'] = null;
        $new['data']['usage']['cost_usd'] = null;
        $gap = $new;
        $gap['seq'] = 3;
        $transport->connections = [FakeEventStreamConnection::fromEnvelopes([$old, $gap]), FakeEventStreamConnection::fromEnvelopes([$new, $gap])];
        $events = iterator_to_array($this->client($transport)->chat('w', Fixtures::CHAT_ID)->events());
        self::assertSame([2, 3, 3], array_map(fn ($event) => $event->envelope->v, $events));
        self::assertSame($old, $events[0]->raw());
        self::assertSame($new, $events[1]->raw());
        self::assertNull($events[1]->usage->costUsd);
        self::assertCount(2, $transport->streamRequests);
        self::assertStringEndsWith('?after=1', $transport->streamRequests[1]->uri);
        foreach ($transport->streamRequests as $request) {
            self::assertSame(['3'], $request->headers['x-envai-contract-version']);
            self::assertStringContainsString('/atp/v3/chats/', $request->uri);
        }
    }

    public function test_unrelated_default_three_transport_and_errors_remain_unchanged(): void
    {
        $transport = new ContractTestTransport;
        $guard = new ContractTransport($transport, new UriResolver('https://envai.example'));
        foreach (['/api/v1/agents', '/.well-known/wiki-webhook-jwks.json', 'relative', 'http://:'] as $path) {
            $request = HttpRequest::post($path);
            $response = new HttpResponse(201, [], '{}');
            $transport->responses = [$response];
            self::assertSame($response, $guard->send($request));
            self::assertSame($request, end($transport->requests));
        }
        $request = new StreamRequest('https://envai.example/unrelated');
        $connection = new FakeEventStreamConnection([]);
        $transport->connections = [$connection];
        self::assertSame($connection, $guard->open($request));
        self::assertSame($request, $transport->streamRequests[0]);
        $transport->connections = [new TransportException('connection interrupted')];
        try {
            $guard->open(new StreamRequest('https://envai.example/api/v1/atp/v3/chats/c/events'));
            self::fail('Transport failure swallowed.');
        } catch (TransportException $e) {
            self::assertSame('connection interrupted', $e->getMessage());
        }
        $connection = new FakeEventStreamConnection([], 503);
        $transport->connections = [$connection];
        try {
            $guard->open(new StreamRequest('https://envai.example/api/v1/atp/v3/chats/c/events'));
            self::fail('Rejected stream accepted.');
        } catch (ContractCompatibilityException) {
            self::assertTrue($connection->isClosed());
        }
    }

    public function test_invalid_legacy_completion_container_and_capabilities_object_fail_closed(): void
    {
        foreach (['{"data":null}', '{"data":"bad"}', '{"data":[42]}', '[]'] as $body) {
            $transport = new ContractTestTransport;
            $transport->responses = [new HttpResponse(200, ['X-Envai-Contract-Version' => '2'], $body)];
            try {
                $this->client($transport, 2)->completions()->list('w', 'c');
                self::fail('Invalid completion representation accepted.');
            } catch (ContractCompatibilityException) {
                self::assertCount(1, $transport->requests);
            }
        }
        $transport = new ContractTestTransport;
        $transport->responses = [new HttpResponse(200, ['X-Envai-Contract-Version' => '3'], '{"0":true,"protocol_version":3}')];
        $this->expectException(ContractCompatibilityException::class);
        $this->client($transport)->capabilities()->getCapabilities();
    }
}
