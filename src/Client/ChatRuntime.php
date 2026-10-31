<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Client\Internal\ChatsMessageSender;
use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Contracts\EventStreamConnection;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Models\Chat\ChatMessageAccepted;
use Digitalygo\Envai\Models\Chat\ChatMessageRequest;
use Digitalygo\Envai\Models\Chat\DelegationRequest;
use Digitalygo\Envai\Models\Chat\TaskSnapshot;
use Digitalygo\Envai\Streaming\Atp\AtpEventDecoder;
use Digitalygo\Envai\Streaming\Atp\ChatEventStream;
use Digitalygo\Envai\Streaming\Atp\ChatTurnRuntime;
use Digitalygo\Envai\Streaming\Atp\DelegatedJobAwait;
use Digitalygo\Envai\Streaming\Atp\TurnResult;
use Digitalygo\Envai\Streaming\Events\AtpEvent;
use Digitalygo\Envai\Streaming\Events\ProtocolViolationEvent;
use Digitalygo\Envai\Tools\Execution\ClientToolRunner;

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
     * Submit a job only after its target SSE is live, and retain that SSE through
     * the final origin task outcome. Cancellation closes observation only; use
     * cancelTask() to cancel execution explicitly. Configure a finite stream
     * timeout or supply a cancellation token for bounded waiting.
     */
    public function delegateAndWait(
        DelegationRequest $request,
        ?ClientToolRunner $runner = null,
        ?CancellationToken $token = null,
    ): TaskSnapshot {
        if ($request->workspaceId !== $this->workspaceId || $request->targetChatId === $this->chatId) {
            throw new \InvalidArgumentException('Delegation must target another chat in this workspace.');
        }
        $this->chats->requireCapability('delegated_job_lifecycle');
        $target = new self($this->workspaceId, $request->targetChatId, $this->chats, $this->contractVersion);
        $stream = $target->events(token: $token);
        try {
            $replay = [];
            $collect = static function (AtpEvent $event) use (&$replay): void {
                if (count($replay) >= 10000) {
                    throw new ProtocolViolationException('Target replay exceeds the event buffer limit.');
                }
                $replay[] = $event;
            };
            if (! $stream->waitLiveConfirmation(onReplay: $collect)) {
                throw new TransportException('Target stream did not confirm a live subscription; delegation was not submitted.');
            }
            $token?->throwIfCancelled();
            $accepted = $this->chats->delegate($this->chatId, $request);
            // Exact event-ID retries may resume an existing job. Establish all
            // replay barriers before admitting any retained pending tool call.
            if ($runner !== null) {
                foreach ($replay as $event) {
                    if ($event instanceof ProtocolViolationEvent) {
                        throw new ProtocolViolationException('Malformed target replay.');
                    }
                    $runner->observe($event);
                }
                $runner->handleBackgroundEvents($replay, $request->targetChatId, liveConfirmed: true);
            }

            return DelegatedJobAwait::terminal(
                $accepted,
                $stream,
                fn (): TaskSnapshot => $this->chats->getTask($this->chatId, $accepted->taskId),
                $runner,
                $token,
            );
        } finally {
            $stream->close();
        }
    }

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
    public function sendMessage(string $message, array $attachmentIds = [], ?string $idempotencyKey = null, ?bool $strictModel = null): ChatMessageAccepted
    {
        return $this->chats->sendMessage($this->chatId, new ChatMessageRequest($message, $attachmentIds, $strictModel), $idempotencyKey);
    }

    /**
     * Sends the message and streams every event of the accepted turn
     * until its first terminal event.
     *
     * @param  list<string>  $attachmentIds
     * @return \Generator<int, AtpEvent, mixed, void>
     */
    public function sendAndStream(string $message, array $attachmentIds = [], ?string $idempotencyKey = null, ?bool $strictModel = null): \Generator
    {
        return $this->runtime($attachmentIds, $idempotencyKey, $strictModel)->sendAndStream($message);
    }

    /**
     * Sends the message and awaits the terminal event of the accepted
     * turn.
     */
    /** @param list<string> $attachmentIds */
    public function sendAndWait(string $message, array $attachmentIds = [], ?string $idempotencyKey = null, ?bool $strictModel = null): TurnResult
    {
        return $this->runtime($attachmentIds, $idempotencyKey, $strictModel)->sendAndWait($message);
    }

    /** @param list<string> $attachmentIds */
    private function runtime(array $attachmentIds, ?string $idempotencyKey, ?bool $strictModel): ChatTurnRuntime
    {
        return new ChatTurnRuntime(
            $this->events(),
            new ChatsMessageSender($this->chats, $attachmentIds, $idempotencyKey, $strictModel),
            $this->chatId,
            retainReplay: $idempotencyKey !== null,
        );
    }
}
