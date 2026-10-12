<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Streaming\Atp;

use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Models\Chat\ChatMessageAccepted;
use Digitalygo\Envai\Streaming\Atp\ChatEventStream;
use Digitalygo\Envai\Streaming\Atp\ChatTurnRuntime;
use Digitalygo\Envai\Streaming\Events\TurnCompletedEvent;
use Digitalygo\Envai\Tests\Streaming\Fixtures;
use PHPUnit\Framework\TestCase;

final class LiveConfirmationRegressionTest extends TestCase
{
    public function test_eof_before_ping_never_sends_and_closes_connection(): void
    {
        $connection = FakeEventStreamConnection::fromChunks([]);
        $sender = new FakeMessageSender(new ChatMessageAccepted('accepted', Fixtures::TURN_ID));
        $runtime = new ChatTurnRuntime(new ChatEventStream($connection, chatId: Fixtures::CHAT_ID), $sender);
        try {
            $runtime->send('must not leave');
            $this->fail('Missing live confirmation must prevent sends.');
        } catch (TransportException) {
            $this->assertSame([], $sender->calls);
            $this->assertTrue($connection->isClosed());
        }
    }

    public function test_old_terminal_replay_does_not_prevent_live_confirmation(): void
    {
        $stream = new ChatEventStream(FakeEventStreamConnection::fromEnvelopes(array_merge(Fixtures::simpleTurn(), [Fixtures::ping()])));
        $this->assertTrue($stream->waitLiveConfirmation());
        $this->assertSame(7, $stream->lastCursor());
    }

    public function test_coalesced_ping_and_turn_events_are_drained_before_eof(): void
    {
        $wire = implode('', array_map(static fn (array $e): string => 'data: '.json_encode($e, JSON_THROW_ON_ERROR)."\n\n", array_merge([Fixtures::ping()], Fixtures::simpleTurn())));
        $connection = FakeEventStreamConnection::fromChunks([$wire]);
        $stream = new ChatEventStream($connection, chatId: Fixtures::CHAT_ID);
        $sender = new FakeMessageSender(new ChatMessageAccepted('accepted', Fixtures::TURN_ID));
        $result = (new ChatTurnRuntime($stream, $sender))->sendAndWait('hello');
        $this->assertInstanceOf(TurnCompletedEvent::class, $result->terminal);
        $this->assertSame(7, $result->lastSeq);
        $this->assertCount(1, $sender->calls);
        $this->assertTrue($connection->isClosed());
    }
}
