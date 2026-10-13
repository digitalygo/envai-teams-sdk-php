<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Wiki;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Exceptions\ContractCompatibilityException;
use Digitalygo\Envai\Exceptions\OperationCancelledException;
use Digitalygo\Envai\Exceptions\ProtocolVersionException;
use Digitalygo\Envai\Http\Client\ContractTransport;
use Digitalygo\Envai\Http\Client\UriResolver;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Http\StreamRequest;
use Digitalygo\Envai\Streaming\Atp\AtpEventDecoder;
use Digitalygo\Envai\Streaming\Events\ProtocolViolationEvent;
use Digitalygo\Envai\Tests\Streaming\Atp\FakeEventStreamConnection;
use PHPUnit\Framework\TestCase;

final class LegacyReplaySafetyTest extends TestCase
{
    private static function legacy(): array
    {
        return [...ContractVersionParsingTest::sample('turn.completed'), 'v' => 2];
    }

    public function test_invalid_late_tail_is_rejected_before_a_connection_or_any_prefix_is_returned(): void
    {
        foreach (['missing_duration', 'null_cost', 'negative_tokens', 'bad_id', 'mixed_v3', 'closed_payload', 'foreign_chat', 'gap', 'duplicate', 'backwards', 'truncated'] as $case) {
            $valid = self::legacy();
            $invalid = $valid;
            $invalid['seq'] = 2;
            match ($case) {
                'missing_duration' => $invalid['data']['usage']['duration_ms'] = null,
                'null_cost' => $invalid['data']['cost'] = null,
                'negative_tokens' => $invalid['data']['usage']['input_tokens'] = -1,
                'bad_id' => $invalid['id'] = 'evt_invalid',
                'mixed_v3' => $invalid['v'] = 3,
                'foreign_chat' => $invalid['chat_id'] = 'cht_01ARZ3NDEKTSV4RRFFQ69G5FA1',
                'gap' => $invalid['seq'] = 3,
                'duplicate' => $invalid['seq'] = 1,
                'backwards' => $invalid['seq'] = 0,
                'closed_payload' => $invalid['data']['unexpected'] = true,
                default => null,
            };
            $transport = new ContractTestTransport;
            $connection = new FakeEventStreamConnection(['data: '.json_encode($valid)."\n\n", 'data: '.json_encode($invalid).($case === 'truncated' ? "\n" : "\n\n")], responseHeaders: ['x-envai-contract-version' => ['2']]);
            $transport->connections = [$connection];
            $client = new EnvaiClient(new EnvaiConfig('https://envai.example', new SensitiveString('test'), contractVersion: 2), $transport);
            $delivered = [];
            try {
                foreach ($client->chat('w', $valid['chat_id'])->events() as $event) {
                    $delivered[] = $event;
                }
                self::fail('Invalid legacy tail accepted: '.$case);
            } catch (ContractCompatibilityException|ProtocolVersionException) {
                self::assertSame([], $delivered);
                self::assertTrue($connection->isClosed());
                self::assertCount(1, $transport->streamRequests);
            }
        }
    }

    public function test_limit_is_configurable_and_never_truncates_or_delivers_a_prefix(): void
    {
        $transport = new ContractTestTransport;
        $connection = new FakeEventStreamConnection([str_repeat(':', 33)], responseHeaders: ['x-envai-contract-version' => ['2']]);
        $transport->connections = [$connection];
        $client = new EnvaiClient(new EnvaiConfig('https://envai.example', new SensitiveString('test'), contractVersion: 2, maxLegacyReplayBytes: 32), $transport);
        try {
            $client->chats()->openLegacyEventStream('c');
            self::fail('Oversized replay accepted.');
        } catch (ContractCompatibilityException $e) {
            self::assertStringContainsString('maxLegacyReplayBytes', $e->getMessage());
            self::assertTrue($connection->isClosed());
        }
        foreach ([fn () => new EnvaiConfig('https://envai.example', new SensitiveString('test'), maxLegacyReplayBytes: 0), fn () => new ContractTransport($transport, new UriResolver('https://envai.example'), 2, 0)] as $invalid) {
            try {
                $invalid();
                self::fail('Invalid bound accepted.');
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_cancellation_during_capture_discards_buffer_and_closes_source(): void
    {
        $token = new class implements CancellationToken
        {
            private int $checks = 0;

            public function isCancelled(): bool
            {
                return $this->checks >= 3;
            }

            public function throwIfCancelled(): void
            {
                if (++$this->checks >= 3) {
                    throw new OperationCancelledException('cancelled');
                }
            }
        };
        $connection = new FakeEventStreamConnection(['data: '.json_encode(self::legacy())."\n\n"], responseHeaders: ['x-envai-contract-version' => ['2']]);
        $transport = new ContractTestTransport;
        $transport->connections = [$connection];
        $guard = new ContractTransport($transport, new UriResolver('https://envai.example'), 2);
        try {
            $guard->open(new StreamRequest('https://envai.example/api/v1/chats/cht_01ARZ3NDEKTSV4RRFFQ69G5FAV/events', cancellationToken: $token));
            self::fail('Cancelled buffer escaped.');
        } catch (OperationCancelledException) {
            self::assertTrue($connection->isClosed());
            self::assertTrue($token->isCancelled());
        }
    }

    public function test_finite_capture_preserves_exact_bytes_handles_bare_cr_and_never_reconnects_on_eof(): void
    {
        $wire = 'data: '.json_encode(self::legacy())."\r\r";
        $transport = new ContractTestTransport;
        $source = new FakeEventStreamConnection(str_split($wire, 7), responseHeaders: ['x-envai-contract-version' => ['2']]);
        $transport->connections = [$source];
        $guard = new ContractTransport($transport, new UriResolver('https://envai.example'), 2);
        $buffer = $guard->open(new StreamRequest('https://envai.example/api/v1/chats/cht_01ARZ3NDEKTSV4RRFFQ69G5FAV/events'));
        self::assertTrue($source->isClosed());
        self::assertFalse($buffer->isClosed());
        self::assertFalse($buffer->isCancelled());
        self::assertSame(200, $buffer->statusCode());
        self::assertSame(['x-envai-contract-version' => ['2']], $buffer->headers());
        $actual = '';
        while (($chunk = $buffer->read(11)) !== null) {
            $actual .= $chunk;
        }
        self::assertSame($wire, $actual);
        self::assertNull($buffer->read());
        self::assertCount(1, $transport->streamRequests);
        try {
            $buffer->read(0);
            self::fail('Invalid read size allowed.');
        } catch (\InvalidArgumentException) {
            self::assertTrue(true);
        }
        $buffer->cancel();
        self::assertTrue($buffer->isClosed());
        self::assertTrue($buffer->isCancelled());
        self::assertNull($buffer->read());
    }

    public function test_legacy_schema_required_fields_unknown_extensions_and_bundle_match(): void
    {
        self::assertSame(file_get_contents(dirname(__DIR__, 2).'/docs/events-v2.json'), file_get_contents(dirname(__DIR__, 2).'/src/resources/atp/events-v2.json'));
        $wire = self::legacy();
        unset($wire['data']['usage']['duration_ms']);
        self::assertInstanceOf(ProtocolViolationEvent::class, (new AtpEventDecoder(2))->decode(json_encode($wire)));
        $wire = self::legacy();
        $wire['type'] = 'future.event';
        $wire['extra_envelope'] = true;
        $wire['data'] = ['unrecognized' => true];
        self::assertNotInstanceOf(ProtocolViolationEvent::class, (new AtpEventDecoder(2))->decode(json_encode($wire)));
    }

    public function test_legacy_rest_recursively_rejects_nonfinite_negative_and_unknown_costs_before_hydration(): void
    {
        foreach (['{"cost_usd":-1}', '{"cost_usd":1e400}', '{"cost_usd":0,"usage":{"cost_usd":null}}', '{"cost_usd":0,"usage":{"agent":{"chat_cost":null}}}', '{"cost_usd":0,"usage":{"agent":{"cost":-1}}}', '{"cost_usd":0,"usage":{"agent":{"cost":1e400}}}'] as $row) {
            $transport = new ContractTestTransport;
            $transport->responses = [new HttpResponse(200, ['x-envai-contract-version' => '2'], '{"data":[{"cost_usd":0},'.$row.']}')];
            $guard = new ContractTransport($transport, new UriResolver('https://envai.example'), 2);
            try {
                $guard->send(HttpRequest::get('https://envai.example/api/v1/workspaces/w/chats/c/completions'));
                self::fail('Malformed legacy total accepted.');
            } catch (ContractCompatibilityException) {
                self::assertCount(1, $transport->requests);
            }
        }
    }

    public function test_cursor_boundaries_and_control_pings_do_not_reconnect_after_eof(): void
    {
        $wire = self::legacy();
        $wire['seq'] = 8;
        $ping = $wire;
        $ping['seq'] = 0;
        $ping['type'] = 'ping';
        $ping['turn_id'] = null;
        $ping['data'] = new \stdClass;
        $transport = new ContractTestTransport;
        $transport->connections = [new FakeEventStreamConnection(['data: '.json_encode($ping)."\n\ndata: ".json_encode($wire)."\r\r"], responseHeaders: ['x-envai-contract-version' => ['2']])];
        $client = new EnvaiClient(new EnvaiConfig('https://envai.example', new SensitiveString('test'), contractVersion: 2), $transport);
        $stream = $client->chat('w', $wire['chat_id'])->events(7);
        $events = iterator_to_array($stream);
        self::assertCount(2, $events);
        self::assertSame([0, 8], array_map(fn ($event) => $event->envelope->seq, $events));
        self::assertSame(8, $stream->lastCursor());
        self::assertCount(1, $transport->streamRequests);
        $transport->connections = [new FakeEventStreamConnection(['data: '.json_encode($wire)."\n\n"], responseHeaders: ['x-envai-contract-version' => ['2']])];
        $this->expectException(ContractCompatibilityException::class);
        $client->chat('w', $wire['chat_id'])->events(8);
    }

    public function test_invalid_legacy_replay_query_closes_connection(): void
    {
        $transport = new ContractTestTransport;
        $source = new FakeEventStreamConnection([], responseHeaders: ['x-envai-contract-version' => ['2']]);
        $transport->connections = [$source];
        $guard = new ContractTransport($transport, new UriResolver('https://envai.example'), 2);
        try {
            $guard->open(new StreamRequest('https://envai.example/api/v1/chats/c/events?after[]=1'));
            self::fail('Invalid cursor accepted.');
        } catch (ContractCompatibilityException) {
            self::assertTrue($source->isClosed());
        }
    }
}
