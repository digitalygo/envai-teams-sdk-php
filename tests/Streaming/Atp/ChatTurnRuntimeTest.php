<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Streaming\Atp;

use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Models\Chat\ChatMessageAccepted;
use Digitalygo\Envai\Streaming\Atp\ChatEventStream;
use Digitalygo\Envai\Streaming\Atp\ChatTurnRuntime;
use Digitalygo\Envai\Streaming\Events\AtpEvent;
use Digitalygo\Envai\Streaming\Events\BillingCreditExhaustedEvent;
use Digitalygo\Envai\Streaming\Events\BillingLimitReachedEvent;
use Digitalygo\Envai\Streaming\Events\ChatCronWakeupEvent;
use Digitalygo\Envai\Streaming\Events\ChatTitleUpdatedEvent;
use Digitalygo\Envai\Streaming\Events\ProtocolViolationEvent;
use Digitalygo\Envai\Streaming\Events\TurnCancelledEvent;
use Digitalygo\Envai\Streaming\Events\TurnCompletedEvent;
use Digitalygo\Envai\Streaming\Events\TurnStartedEvent;
use Digitalygo\Envai\Tests\Streaming\Fixtures;
use PHPUnit\Framework\TestCase;

final class ChatTurnRuntimeTest extends TestCase
{
    private const MESSAGE = 'Analizza il traffico';

    /**
     * @param  list<array<string, mixed>>  $turnEvents  The turn sequence, delivered after a leading ping.
     */
    private function runtime(array $turnEvents, string $turnId = Fixtures::TURN_ID): array
    {
        $frames = array_merge([Fixtures::ping()], $turnEvents);
        $connection = FakeEventStreamConnection::fromEnvelopes($frames);
        $stream = new ChatEventStream($connection, chatId: Fixtures::CHAT_ID);
        $sender = new FakeMessageSender(new ChatMessageAccepted('accepted', $turnId));

        return [$stream, $sender, new ChatTurnRuntime($stream, $sender, Fixtures::CHAT_ID)];
    }

    public function test_send_waits_for_live_confirmation_before_sending(): void
    {
        [, $sender, $runtime] = $this->runtime(Fixtures::simpleTurn());

        $accepted = $runtime->send(self::MESSAGE);

        $this->assertSame('accepted', $accepted->status);
        $this->assertSame(Fixtures::TURN_ID, $accepted->turnId);
        $this->assertSame([['chatId' => Fixtures::CHAT_ID, 'message' => self::MESSAGE]], $sender->calls);
    }

    public function test_idempotent_acceptance_returns_the_completed_turn_replayed_before_ping(): void
    {
        foreach ([true, false] as $streaming) {
            $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes([...Fixtures::simpleTurn(), Fixtures::ping()]), chatId: Fixtures::CHAT_ID);
            $sender = new FakeMessageSender(new ChatMessageAccepted('accepted', Fixtures::TURN_ID));
            $runtime = new ChatTurnRuntime($stream, $sender, Fixtures::CHAT_ID, retainReplay: true);
            if ($streaming) {
                $events = iterator_to_array($runtime->sendAndStream(self::MESSAGE));
                $this->assertCount(7, $events);
                $this->assertInstanceOf(TurnCompletedEvent::class, $events[6]);
            } else {
                $result = $runtime->sendAndWait(self::MESSAGE);
                $this->assertInstanceOf(TurnCompletedEvent::class, $result->terminal);
                $this->assertStringContainsString('12k visitatori', $result->orchestratorText);
            }
            $this->assertCount(1, $sender->calls);
        }
    }

    public function test_idempotent_send_rejects_excessive_replay_before_submitting(): void
    {
        $frames = [];
        for ($seq = 1; $seq <= 34; $seq++) {
            $event = Fixtures::sample('agent.text.delta');
            $event['seq'] = $seq;
            $event['data']['delta'] = str_repeat('x', 512 * 1024);
            $frames[] = $event;
        }
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes($frames), chatId: Fixtures::CHAT_ID);
        $sender = new FakeMessageSender(new ChatMessageAccepted('accepted', Fixtures::TURN_ID));
        $runtime = new ChatTurnRuntime($stream, $sender, Fixtures::CHAT_ID, retainReplay: true);
        try {
            $runtime->send(self::MESSAGE);
            $this->fail('Excessive replay must not submit a message');
        } catch (TransportException $error) {
            $this->assertStringContainsString('exceeds 16 MiB', $error->getMessage());
            $this->assertSame([], $sender->calls);
        }
    }

    public function test_send_and_stream_yields_only_events_of_the_accepted_turn(): void
    {
        $otherTurn = Fixtures::resequence(array_slice(Fixtures::simpleTurn(), 0, 1), 0);
        $otherTurn[0]['turn_id'] = 'cmpl_01j5abcdefghjkmnpqrstuvwxyzX';
        $otherTurn[0]['seq'] = 1;
        $otherTurn[0]['data']['turn_id'] = 'cmpl_01j5abcdefghjkmnpqrstuvwxyzX';

        $frames = array_merge([$otherTurn[0]], Fixtures::resequence(Fixtures::simpleTurn(), 1));
        [$stream, , $runtime] = $this->runtime($frames);

        $events = iterator_to_array($runtime->sendAndStream(self::MESSAGE));

        $this->assertNotContains('cmpl_01j5abcdefghjkmnpqrstuvwxyzX', array_map(
            static fn (AtpEvent $event): string => $event->envelope->turnId ?? '',
            $events,
        ));
        $this->assertSame([2, 3, 4, 5, 6, 7, 8], array_map(static fn (AtpEvent $event): int => $event->envelope->seq, $events));
        $this->assertSame(8, $stream->lastCursor());
    }

    public function test_send_and_stream_stops_at_the_terminal_event(): void
    {
        [$stream, , $runtime] = $this->runtime(Fixtures::simpleTurn());

        $events = iterator_to_array($runtime->sendAndStream(self::MESSAGE));

        $this->assertCount(7, $events);
        $this->assertInstanceOf(TurnStartedEvent::class, $events[0]);
        $this->assertInstanceOf(TurnCompletedEvent::class, $events[6]);
        $this->assertSame(7, $stream->lastCursor());
    }

    public function test_additional_events_do_not_terminate_the_turn(): void
    {
        $terminal = Fixtures::sample('turn.completed');
        $terminal['seq'] = 6;
        $frames = [
            Fixtures::sample('turn.started'),
            self::additionalWire(2, 'chat.title.updated', ['title' => 'Title']),
            self::additionalWire(3, 'chat.cron.wakeup', [
                'cron_id' => 'cron_01ARZ3NDEKTSV4RRFFQ69G5FAV',
                'occurrence_id' => 'occ_01ARZ3NDEKTSV4RRFFQ69G5FAV',
                'scheduled_at' => '2026-01-01T00:00:00Z',
                'delivered_at' => '2026-01-01T00:01:00Z',
                'prompt' => 'Do it',
                'target_mode' => 'same_chat',
                'dispatch_mode' => 'turn_injected',
            ]),
            self::additionalWire(4, 'billing.limit_reached', [
                'reason' => 'limit_reserved',
                'scope' => 'api_key',
                'currency' => 'EUR',
                'amount' => '1.000000000001',
                'remaining' => '0.000000000000',
                'reset_at' => null,
            ]),
            self::additionalWire(5, 'billing.credit_exhausted', ['reason' => 'credit_reserved', 'scope' => 'company', 'currency' => 'EUR']),
            $terminal,
        ];
        [$stream, , $runtime] = $this->runtime($frames);

        $events = iterator_to_array($runtime->sendAndStream(self::MESSAGE));

        $this->assertCount(6, $events);
        $this->assertInstanceOf(TurnStartedEvent::class, $events[0]);
        $this->assertInstanceOf(ChatTitleUpdatedEvent::class, $events[1]);
        $this->assertInstanceOf(ChatCronWakeupEvent::class, $events[2]);
        $this->assertInstanceOf(BillingLimitReachedEvent::class, $events[3]);
        $this->assertInstanceOf(BillingCreditExhaustedEvent::class, $events[4]);
        $this->assertInstanceOf(TurnCompletedEvent::class, $events[5]);
        $this->assertSame(6, $stream->lastCursor());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private static function additionalWire(int $seq, string $type, array $data, ?string $turnId = Fixtures::TURN_ID): array
    {
        return Fixtures::envelope($seq, sprintf('evt_%02d', $seq), '2026-08-17T14:00:00Z', $type, $data, null, $turnId);
    }

    public function test_send_and_wait_aggregates_terminal_usage_and_orchestrator_text(): void
    {
        [, , $runtime] = $this->runtime(Fixtures::simpleTurn());

        $result = $runtime->sendAndWait(self::MESSAGE);

        $this->assertSame(Fixtures::CHAT_ID, $result->chatId);
        $this->assertSame(Fixtures::TURN_ID, $result->turnId);
        $this->assertInstanceOf(TurnCompletedEvent::class, $result->terminal);
        $this->assertSame('completed', $result->terminal->finishReason);
        $this->assertSame("Ecco l'analisi del traffico: 12k visitatori, +8% rispetto alla scorsa settimana.", $result->orchestratorText);
        $this->assertSame(120, $result->lastUsage?->inputTokens);
        $this->assertSame(450, $result->lastUsage?->outputTokens);
        $this->assertSame(0.42, $result->lastUsage?->costUsd);
        $this->assertSame(7, $result->lastSeq);
        $this->assertNull($result->terminalViolation);
    }

    public function test_send_and_wait_uses_examples_simple_turn_sequence_verbatim(): void
    {
        // The EXAMPLES.md "Simple turn" wire lines, JSON-decoded verbatim.
        $wire = file_get_contents(__DIR__.'/fixtures/simple-turn.sse');
        $this->assertNotFalse($wire);
        $frames = [Fixtures::ping()];
        foreach (preg_split('/\r?\n/', $wire, -1, PREG_SPLIT_NO_EMPTY) as $line) {
            if (str_starts_with($line, 'data: ')) {
                $frames[] = json_decode(substr($line, 6), true, 512, JSON_THROW_ON_ERROR);
            }
        }

        $connection = FakeEventStreamConnection::fromEnvelopes($frames);
        $stream = new ChatEventStream($connection, chatId: Fixtures::CHAT_ID);
        $sender = new FakeMessageSender(new ChatMessageAccepted('accepted', Fixtures::TURN_ID));
        $runtime = new ChatTurnRuntime($stream, $sender, Fixtures::CHAT_ID);

        $result = $runtime->sendAndWait(self::MESSAGE);

        $this->assertInstanceOf(TurnCompletedEvent::class, $result->terminal);
        $this->assertSame("Ecco l'analisi del traffico: 12k visitatori, +8% rispetto alla scorsa settimana.", $result->orchestratorText);
        $this->assertSame(7, $result->lastSeq);
    }

    public function test_send_and_wait_falls_back_to_concatenated_orchestrator_deltas(): void
    {
        $turn = array_values(array_filter(
            Fixtures::simpleTurn(),
            static fn (array $event): bool => $event['type'] !== 'agent.text.done',
        ));

        foreach ($turn as $index => $event) {
            $turn[$index]['seq'] = $index + 1;
        }

        [, , $runtime] = $this->runtime($turn);

        $result = $runtime->sendAndWait(self::MESSAGE);

        $this->assertInstanceOf(TurnCompletedEvent::class, $result->terminal);
        $this->assertSame("Ecco l'analisi del traffico:", $result->orchestratorText);
    }

    public function test_send_and_wait_correlates_the_first_terminal_of_the_turn(): void
    {
        // Two turns in one stream: the awaited turn ends at seq 10, a later
        // turn continues after it and must not win the correlation.
        $first = Fixtures::resequence(Fixtures::secondTurn(), 0); // seq 8..10
        $second = Fixtures::resequence(Fixtures::simpleTurn(), 10); // seq 11..17
        $frames = array_merge($first, $second);

        $connection = FakeEventStreamConnection::fromEnvelopes(array_merge([Fixtures::ping()], $frames));
        $stream = new ChatEventStream($connection, chatId: Fixtures::CHAT_ID, initialCursor: 7);
        $sender = new FakeMessageSender(new ChatMessageAccepted('accepted', 'cmpl_01j5abcdefghjkmnpqrstuvwxyA'));
        $runtime = new ChatTurnRuntime($stream, $sender, Fixtures::CHAT_ID);

        $result = $runtime->sendAndWait(self::MESSAGE);

        $this->assertInstanceOf(TurnCompletedEvent::class, $result->terminal);
        $this->assertSame('cmpl_01j5abcdefghjkmnpqrstuvwxyA', $result->turnId);
        $this->assertSame(10, $result->lastSeq);
        $this->assertSame(0.5, $result->lastUsage?->costUsd);
    }

    public function test_send_and_wait_correlates_a_cancelled_turn(): void
    {
        $cancelled = Fixtures::sample('turn.cancelled');
        $cancelled['data'] = [
            'turn_id' => Fixtures::TURN_ID,
            'reason' => 'user_cancelled',
            'usage' => Fixtures::usage(10, 20, 0.01, 500),
            'cost' => 0.01,
            'chat_usage' => Fixtures::usage(10, 20, 0.01, 500),
            'chat_cost' => 0.01,
        ];

        [, , $runtime] = $this->runtime([$cancelled], Fixtures::TURN_ID);

        $result = $runtime->sendAndWait(self::MESSAGE);

        $this->assertInstanceOf(TurnCancelledEvent::class, $result->terminal);
        $this->assertSame('user_cancelled', $result->terminal->reason);
        $this->assertNull($result->orchestratorText);
    }

    public function test_send_and_wait_exposes_a_violated_terminal_prominently(): void
    {
        // Alpha defect: interrupted turn.failed with an empty usage object.
        $failed = Fixtures::sample('turn.failed');
        $failed['data'] = [
            'turn_id' => Fixtures::TURN_ID,
            'code' => 'interrupted',
            'message' => 'Turno interrotto',
            'usage' => [],
            'cost' => 0.0,
            'chat_usage' => [],
            'chat_cost' => 0.0,
        ];

        [, , $runtime] = $this->runtime([$failed], Fixtures::TURN_ID);

        $result = $runtime->sendAndWait(self::MESSAGE);

        $this->assertNull($result->terminal);
        $this->assertNull($result->lastUsage); // the SDK never fabricates usage
        $this->assertInstanceOf(ProtocolViolationEvent::class, $result->terminalViolation);
        $this->assertSame('turn.failed', $result->terminalViolation->envelope->type);
        $this->assertNotEmpty($result->terminalViolation->violations);
        $this->assertSame(Fixtures::TURN_ID, $result->terminalViolation->data['turn_id']);
    }

    public function test_send_and_stream_delivers_the_delegation_sequence_in_wire_order(): void
    {
        // Delegation sequence (seq 11..18) resequenced contiguously after the
        // accepted turn.started and rewritten to the accepted turn id.
        $delegation = array_map(
            static function (array $event): array {
                $event['turn_id'] = Fixtures::TURN_ID;

                return $event;
            },
            Fixtures::resequence(Fixtures::delegation(), -9),
        );
        $turnStarted = Fixtures::sample('turn.started');
        $turnStarted['seq'] = 1;
        $turnStarted['data']['turn_id'] = Fixtures::TURN_ID;
        $turnStarted['turn_id'] = Fixtures::TURN_ID;

        $frames = array_merge([$turnStarted], $delegation);
        [, , $runtime] = $this->runtime($frames, Fixtures::TURN_ID);

        $events = iterator_to_array($runtime->sendAndStream(self::MESSAGE));

        $types = array_map(static fn (AtpEvent $event): string => $event->envelope->type, $events);

        $this->assertSame([
            'turn.started',
            'agent.delegated',
            'agent.started',
            'agent.reasoning.delta',
            'agent.tool.start',
            'agent.tool.complete',
            'agent.text.delta',
            'agent.done',
            'agent.text.delta',
        ], $types);
    }

    public function test_send_and_wait_on_delegation_fixture_uses_delta_fallback_and_no_terminal(): void
    {
        // The EXAMPLES.md delegation sequence has no terminal: the stream
        // ends, so the result reports the deltas and a null terminal.
        $delegation = array_map(
            static function (array $event): array {
                $event['turn_id'] = Fixtures::TURN_ID;

                return $event;
            },
            Fixtures::resequence(Fixtures::delegation(), -9),
        );
        $turnStarted = Fixtures::sample('turn.started');
        $turnStarted['seq'] = 1;
        $turnStarted['data']['turn_id'] = Fixtures::TURN_ID;
        $turnStarted['turn_id'] = Fixtures::TURN_ID;

        $frames = array_merge([$turnStarted], $delegation);
        [, , $runtime] = $this->runtime($frames, Fixtures::TURN_ID);

        $result = $runtime->sendAndWait(self::MESSAGE);

        $this->assertNull($result->terminal);
        $this->assertNull($result->lastUsage);
        $this->assertSame('Sintesi: 12k visitatori, +8% rispetto alla scorsa settimana.', $result->orchestratorText);
    }
}
