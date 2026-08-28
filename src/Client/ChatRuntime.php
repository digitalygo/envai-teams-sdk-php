<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\ChatsMessageSender;
use Digitalygo\Envai\Contracts\EventStreamConnection;
use Digitalygo\Envai\Models\Chat\ChatMessageAccepted;
use Digitalygo\Envai\Models\Chat\ChatMessageRequest;
use Digitalygo\Envai\Streaming\Atp\ChatEventStream;
use Digitalygo\Envai\Streaming\Atp\ChatTurnRuntime;
use Digitalygo\Envai\Streaming\Atp\TurnResult;
use Digitalygo\Envai\Streaming\Events\AtpEvent;

/**
 * High level facade over one chat: the typed ATP event stream plus the
 * turn helpers of project document section 11.
 *
 * events() opens the multiplexed SSE stream through ChatsService (the
 * bearer Authorization header is attached by the service) and wires a
 * connection factory that re-opens the same endpoint with `after=cursor`
 * for replay gaps, so a gap never re-reads from a foreign chat.
 *
 * sendMessage(), sendAndStream() and sendAndWait() wrap the shared
 * ChatTurnRuntime helpers: each call opens its own stream, waits for the
 * live-subscription confirmation, sends the message and consumes the
 * accepted turn.
 */
final class ChatRuntime
{
    public function __construct(
        private readonly string $workspaceId,
        private readonly string $chatId,
        private readonly ChatsService $chats,
    ) {}

    /**
     * The workspace this runtime is bound to.
     */
    public function workspaceId(): string
    {
        return $this->workspaceId;
    }

    /**
     * Opens the typed ATP event stream of the chat, replaying every
     * retained event strictly after the given cursor.
     */
    public function events(int $after = 0): ChatEventStream
    {
        return new ChatEventStream(
            $this->chats->openEventStream($this->chatId, $after),
            chatId: $this->chatId,
            connectionFactory: fn (int $cursor): EventStreamConnection => $this->chats->openEventStream($this->chatId, $cursor),
        );
    }

    /**
     * Sends a user message that opens a turn and returns the accepted
     * acknowledgment (POST /chats/{chat_id}/messages, HTTP 202).
     */
    public function sendMessage(string $message): ChatMessageAccepted
    {
        return $this->chats->sendMessage($this->chatId, $this->messageRequest($message));
    }

    /**
     * Sends the message and streams every event of the accepted turn
     * until its first terminal event.
     *
     * @return \Generator<int, AtpEvent, mixed, void>
     */
    public function sendAndStream(string $message): \Generator
    {
        return $this->runtime()->sendAndStream($message);
    }

    /**
     * Sends the message and awaits the terminal event of the accepted
     * turn.
     */
    public function sendAndWait(string $message): TurnResult
    {
        return $this->runtime()->sendAndWait($message);
    }

    private function runtime(): ChatTurnRuntime
    {
        return new ChatTurnRuntime(
            $this->events(),
            new ChatsMessageSender($this->chats),
            $this->chatId,
        );
    }

    private function messageRequest(string $message): ChatMessageRequest
    {
        return new ChatMessageRequest($message);
    }
}
