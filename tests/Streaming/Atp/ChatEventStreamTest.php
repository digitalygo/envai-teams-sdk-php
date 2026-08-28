<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Streaming\Atp;

use Digitalygo\Envai\Exceptions\OperationCancelledException;
use Digitalygo\Envai\Exceptions\ReplayGapException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Streaming\Atp\ChatEventStream;
use Digitalygo\Envai\Streaming\Events\AgentDoneEvent;
use Digitalygo\Envai\Streaming\Events\AgentStartedEvent;
use Digitalygo\Envai\Streaming\Events\AgentToolRequestEvent;
use Digitalygo\Envai\Streaming\Events\AtpEvent;
use Digitalygo\Envai\Streaming\Events\PingEvent;
use Digitalygo\Envai\Streaming\Events\ProtocolViolationEvent;
use Digitalygo\Envai\Streaming\Events\UnknownEvent;
use Digitalygo\Envai\Tests\Streaming\Fixtures;
use PHPUnit\Framework\TestCase;

final class ChatEventStreamTest extends TestCase
{
    /**
     * @param  list<array<string, mixed>>  $envelopes
     * @return list<array<string, mixed>>
     */
    private function sseChunks(array $envelopes, int $splitEvery = 8192): array
    {
        $bytes = '';

        foreach ($envelopes as $envelope) {
            $bytes .= 'data: '.json_encode($envelope, JSON_THROW_ON_ERROR)."\n\n";
        }

        return array_map('strval', str_split($bytes, $splitEvery));
    }

    /**
     * @param  list<AtpEvent>  $events
     * @return list<int>
     */
    private function seqs(array $events): array
    {
        return array_map(static fn (AtpEvent $event): int => $event->envelope->seq, $events);
    }

    public function test_fragmented_bytes_across_reads_deliver_all_events_in_order(): void
    {
        $connection = FakeEventStreamConnection::fromChunks($this->sseChunks(Fixtures::simpleTurn(), 40));
        $stream = new ChatEventStream($connection);

        $events = iterator_to_array($stream->getIterator());

        $this->assertCount(7, $events);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->seqs($events));
        $this->assertSame(7, $stream->lastCursor());
    }

    public function test_multiple_frames_per_chunk(): void
    {
        $connection = FakeEventStreamConnection::fromChunks($this->sseChunks(Fixtures::simpleTurn(), 1024));
        $stream = new ChatEventStream($connection);

        $events = iterator_to_array($stream->getIterator());

        $this->assertCount(7, $events);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->seqs($events));
    }

    public function test_ping_is_delivered_but_never_advances_the_cursor(): void
    {
        $frames = array_merge(
            [Fixtures::ping('evt_01j5abcdefghjkmnpqrstuvwxy9')],
            Fixtures::simpleTurn(),
            [Fixtures::ping('evt_01j5abcdefghjkmnpqrstuvwxyA', '2026-08-17T14:01:00Z')],
        );
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes($frames));

        $events = iterator_to_array($stream->getIterator());

        $this->assertInstanceOf(PingEvent::class, $events[0]);
        $this->assertInstanceOf(PingEvent::class, $events[8]);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->seqs(array_slice($events, 1, 7)));
        $this->assertSame(7, $stream->lastCursor());
    }

    public function test_duplicate_events_are_skipped_silently(): void
    {
        $duplicate = Fixtures::simpleTurn()[0];
        $frames = array_merge([$duplicate, $duplicate], Fixtures::simpleTurn());
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes($frames));

        $events = iterator_to_array($stream->getIterator());

        $this->assertCount(7, $events);
        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->seqs($events));
        $this->assertSame(7, $stream->lastCursor());
    }

    public function test_clean_eof_ends_iteration(): void
    {
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes(Fixtures::simpleTurn()));

        $count = 0;

        foreach ($stream as $event) {
            $count++;
        }

        $this->assertSame(7, $count);
        $this->assertSame(7, $stream->lastCursor());
    }

    public function test_recoverable_gap_replays_missing_events(): void
    {
        $turn = Fixtures::simpleTurn();
        $first = array_merge(array_slice($turn, 0, 3), array_slice($turn, 4)); // seq 1..3, then 5..7 (4 missing)
        $replay = array_slice($turn, 3); // seq 4..7 replayed after=3

        $factoryCalls = 0;
        $factory = function (int $after) use (&$factoryCalls, $replay): FakeEventStreamConnection {
            $factoryCalls++;
            $this->assertSame(3, $after);

            return FakeEventStreamConnection::fromEnvelopes($replay);
        };

        $stream = new ChatEventStream(
            FakeEventStreamConnection::fromEnvelopes($first),
            connectionFactory: $factory,
        );

        $events = iterator_to_array($stream->getIterator());

        $this->assertSame([1, 2, 3, 4, 5, 6, 7], $this->seqs($events));
        $this->assertSame(1, $factoryCalls);
        $this->assertSame(7, $stream->lastCursor());
    }

    public function test_unrecoverable_gap_throws_replay_gap_exception(): void
    {
        $turn = Fixtures::simpleTurn();
        $gapEvent = $this->farEvent(8);
        $first = array_merge(array_slice($turn, 0, 3), [$gapEvent]); // seq 1..3, then 8
        $replay = [$gapEvent]; // the replay window lost 4..7

        $stream = new ChatEventStream(
            FakeEventStreamConnection::fromEnvelopes($first),
            connectionFactory: fn (): FakeEventStreamConnection => FakeEventStreamConnection::fromEnvelopes($replay),
        );

        try {
            iterator_to_array($stream->getIterator());
            $this->fail('Expected ReplayGapException');
        } catch (ReplayGapException $e) {
            $this->assertSame(4, $e->missingFrom);
            $this->assertSame(7, $e->missingTo);
        }
    }

    public function test_gap_without_factory_throws_replay_gap_exception(): void
    {
        $turn = Fixtures::simpleTurn();
        $frames = array_merge(array_slice($turn, 0, 3), [$this->farEvent(8)]);
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes($frames));

        try {
            iterator_to_array($stream->getIterator());
            $this->fail('Expected ReplayGapException');
        } catch (ReplayGapException $e) {
            $this->assertSame(4, $e->missingFrom);
            $this->assertSame(7, $e->missingTo);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function farEvent(int $seq): array
    {
        $envelope = Fixtures::sample('agent.plan');
        $envelope['seq'] = $seq;
        $envelope['id'] = sprintf('evt_01j5abcdefghjkmnpqrstuvwxy%d', $seq);

        return $envelope;
    }

    public function test_exhausted_reconnect_attempts_throw_transport_exception(): void
    {
        $turn = Fixtures::simpleTurn();
        $frames = array_merge(array_slice($turn, 0, 3), [$this->farEvent(8)]);
        $factoryCalls = 0;
        $factory = function () use (&$factoryCalls): FakeEventStreamConnection {
            $factoryCalls++;

            return FakeEventStreamConnection::fromEnvelopes([]); // opens but delivers nothing
        };

        $stream = new ChatEventStream(
            FakeEventStreamConnection::fromEnvelopes($frames),
            connectionFactory: $factory,
            maxReconnectAttempts: 2,
            reconnectBaseDelayMs: 0,
            jitterMs: 0,
        );

        try {
            iterator_to_array($stream->getIterator());
            $this->fail('Expected TransportException');
        } catch (TransportException) {
            $this->assertSame(2, $factoryCalls);
        }
    }

    public function test_cancellation_mid_read_throws_operation_cancelled(): void
    {
        $token = new FakeCancellationToken;
        $connection = FakeEventStreamConnection::fromEnvelopes(Fixtures::simpleTurn(), waitToken: $token);
        $stream = new ChatEventStream($connection, token: $token);

        $generator = $stream->getIterator();
        $seen = 0;

        try {
            foreach ($generator as $event) {
                $seen++;
                if ($seen === 3) {
                    $token->cancel();
                }
            }
            $this->fail('Expected OperationCancelledException');
        } catch (OperationCancelledException) {
            $this->assertSame(3, $seen);
        }
    }

    public function test_interleaved_orchestrator_and_subagent_events_stay_in_wire_order(): void
    {
        $frames = array_merge(Fixtures::delegation(), Fixtures::escalationApproval());
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes($frames), initialCursor: 10);

        $events = iterator_to_array($stream->getIterator());

        $this->assertCount(12, $events);
        $this->assertSame([11, 12, 13, 14, 15, 16, 17, 18, 19, 20, 21, 22], $this->seqs($events));

        $expectedTypes = [
            'agent.delegated',
            'agent.started',
            'agent.reasoning.delta',
            'agent.tool.start',
            'agent.tool.complete',
            'agent.text.delta',
            'agent.done',
            'agent.text.delta',
            'agent.escalate',
            'approval.request',
            'approval.response',
            'agent.escalation.result',
        ];

        foreach ($events as $i => $event) {
            $this->assertSame($expectedTypes[$i], $event->envelope->type, 'event '.$i.' type mismatch');
        }
    }

    public function test_initial_cursor_skips_earlier_events(): void
    {
        $stream = new ChatEventStream(
            FakeEventStreamConnection::fromEnvelopes(Fixtures::simpleTurn()),
            initialCursor: 4,
        );

        $events = iterator_to_array($stream->getIterator());

        $this->assertSame([5, 6, 7], $this->seqs($events));
        $this->assertSame(7, $stream->lastCursor());
    }

    public function test_initial_cursor_is_reported_by_last_cursor(): void
    {
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes([]), initialCursor: 3);

        $this->assertSame(3, $stream->lastCursor());
    }

    public function test_protocol_violation_event_is_delivered_not_fatal(): void
    {
        $broken = Fixtures::sample('turn.started');
        unset($broken['data']['input']);
        $broken['seq'] = 1;

        $frames = [$broken, Fixtures::simpleTurn()[1]];
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes($frames));

        $events = iterator_to_array($stream->getIterator());

        $this->assertInstanceOf(ProtocolViolationEvent::class, $events[0]);
        $this->assertInstanceOf(AgentStartedEvent::class, $events[1]);
        $this->assertSame([1, 2], $this->seqs($events));
    }

    public function test_unknown_event_is_delivered(): void
    {
        $unknown = Fixtures::envelope(1, 'evt_01j5abcdefghjkmnpqrstuvwxy1', '2026-08-17T14:00:00Z', 'future.feature', ['x' => 1], null);
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes([$unknown]));

        $events = iterator_to_array($stream->getIterator());

        $this->assertInstanceOf(UnknownEvent::class, $events[0]);
        $this->assertSame(['x' => 1], $events[0]->data->toArray());
        $this->assertSame(1, $stream->lastCursor());
    }

    public function test_wait_live_confirmation_returns_true_after_ping(): void
    {
        $frames = array_merge([Fixtures::ping()], Fixtures::simpleTurn());
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes($frames));

        $confirmed = $stream->waitLiveConfirmation(5);

        $this->assertTrue($confirmed);
        $this->assertTrue($stream->isLiveConfirmed());
        $this->assertSame(0, $stream->lastCursor());
    }

    public function test_wait_live_confirmation_returns_false_on_timeout(): void
    {
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes(Fixtures::simpleTurn()));

        $confirmed = $stream->waitLiveConfirmation(0);

        $this->assertFalse($confirmed);
        $this->assertFalse($stream->isLiveConfirmed());
    }

    public function test_wait_live_confirmation_consumes_preturn_events_advancing_the_cursor(): void
    {
        $frames = [Fixtures::simpleTurn()[0], Fixtures::ping()];
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes($frames));

        $confirmed = $stream->waitLiveConfirmation(5);

        $this->assertTrue($confirmed);
        $this->assertSame(1, $stream->lastCursor());
    }

    public function test_wait_live_confirmation_returns_false_on_terminal_event(): void
    {
        $frames = [Fixtures::simpleTurn()[6]]; // turn.completed before any ping
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes($frames));

        $confirmed = $stream->waitLiveConfirmation(5);

        $this->assertFalse($confirmed);
        $this->assertSame(7, $stream->lastCursor());
    }

    public function test_close_is_idempotent_and_ends_iteration(): void
    {
        $connection = FakeEventStreamConnection::fromEnvelopes(Fixtures::simpleTurn());
        $stream = new ChatEventStream($connection);

        $stream->close();
        $stream->close();

        $events = iterator_to_array($stream->getIterator());

        $this->assertSame([], $events);
        $this->assertTrue($connection->isClosed());
    }

    public function test_single_reader_contract_rejects_a_second_iteration(): void
    {
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes(Fixtures::simpleTurn()));

        iterator_to_array($stream->getIterator());

        $this->expectException(\LogicException::class);

        $stream->getIterator();
    }

    public function test_chat_id_is_learned_from_the_first_envelope(): void
    {
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes(Fixtures::simpleTurn()));

        $this->assertNull($stream->chatId());
        iterator_to_array($stream->getIterator());

        $this->assertSame(Fixtures::CHAT_ID, $stream->chatId());
    }

    public function test_client_tool_signature_block_is_preserved_raw(): void
    {
        $frames = Fixtures::clientTool();
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes($frames), initialCursor: 22);

        $events = iterator_to_array($stream->getIterator());

        $this->assertInstanceOf(AgentToolRequestEvent::class, $events[0]);
        $this->assertSame(['order_id' => 'ord_123'], $events[0]->args->toArray());
        $this->assertSame('EdDSA', $events[0]->signature->alg);
        $this->assertSame('4Z7vK1QpR8wXcB2mN6sT9yDfG0hJqLzMxVbNcAsDfGhJkLzXcVbNmQsWtY', $events[0]->signature->sig);
        $this->assertSame(24, $stream->lastCursor());
    }

    public function test_delegation_sequence_delivers_agent_text_done_and_done_typed(): void
    {
        $frames = Fixtures::delegation();
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes($frames), initialCursor: 10);

        $events = iterator_to_array($stream->getIterator());

        $this->assertInstanceOf(AgentDoneEvent::class, $events[6]);
        $this->assertSame('Dati raccolti', $events[6]->summary);
        $this->assertSame(18, $stream->lastCursor());
    }
}
