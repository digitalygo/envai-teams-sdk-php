<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\ChatsMessageSender;
use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Contracts\EventStreamConnection;
use Digitalygo\Envai\Models\Chat\ChatMessageAccepted;
use Digitalygo\Envai\Models\Chat\ChatMessageRequest;
use Digitalygo\Envai\Streaming\Atp\AtpEventDecoder;
use Digitalygo\Envai\Streaming\Atp\ChatEventStream;
use Digitalygo\Envai\Streaming\Atp\ChatTurnRuntime;
use Digitalygo\Envai\Streaming\Atp\TurnResult;
use Digitalygo\Envai\Streaming\Events\AtpEvent;

/**
 * High level facade over one chat: the typed ATP event stream plus the
 * turn helpers for sending and awaiting messages.
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
        private readonly int $contractVersion = 3,
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
    public function events(int $after = 0, ?CancellationToken $token = null): ChatEventStream
    {
        return new ChatEventStream(
            $this->chats->openEventStream($this->chatId, $after, $token),
            token: $token,
            initialCursor: $after,
            decoder: new AtpEventDecoder($this->contractVersion),
            chatId: $this->chatId,
            connectionFactory: $this->contractVersion === 2 ? null : fn (int $cursor): EventStreamConnection => $this->chats->openEventStream($this->chatId, $cursor, $token),
        );
    }

    /**
     * Sends a user message that opens a turn and returns the accepted
     * acknowledgment (POST /chats/{chat_id}/messages, HTTP 202).
     */
    /** @param list<string> $attachmentIds */
    public function sendMessage(string $message, array $attachmentIds = [], ?string $idempotencyKey = null): ChatMessageAccepted
    {
        return $this->chats->sendMessage($this->chatId, new ChatMessageRequest($message, $attachmentIds), $idempotencyKey);
    }

    /**
     * Sends the message and streams every event of the accepted turn
     * until its first terminal event.
     *
     * @param  list<string>  $attachmentIds
     * @return \Generator<int, AtpEvent, mixed, void>
     */
    public function sendAndStream(string $message, array $attachmentIds = [], ?string $idempotencyKey = null): \Generator
    {
        return $this->runtime($attachmentIds, $idempotencyKey)->sendAndStream($message);
    }

    /**
     * Sends the message and awaits the terminal event of the accepted
     * turn.
     */
    /** @param list<string> $attachmentIds */
    public function sendAndWait(string $message, array $attachmentIds = [], ?string $idempotencyKey = null): TurnResult
    {
        return $this->runtime($attachmentIds, $idempotencyKey)->sendAndWait($message);
    }

    /** @param list<string> $attachmentIds */
    private function runtime(array $attachmentIds, ?string $idempotencyKey): ChatTurnRuntime
    {
        return new ChatTurnRuntime(
            $this->events(),
            new ChatsMessageSender($this->chats, $attachmentIds, $idempotencyKey),
            $this->chatId,
            retainReplay: $idempotencyKey !== null,
        );
    }
}
