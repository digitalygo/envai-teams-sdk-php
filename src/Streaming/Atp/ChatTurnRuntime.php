<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Atp;

use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Models\Chat\ChatMessageAccepted;
use Digitalygo\Envai\Streaming\Events\AgentToolRequestEvent;
use Digitalygo\Envai\Streaming\Events\AgentToolResultEvent;
use Digitalygo\Envai\Streaming\Events\AtpEvent;

/**
 * High level turn helpers over a shared ChatEventStream.
 *
 * Every helper first waits for the live-subscription confirmation (the
 * send-message race workaround), then sends the message and consumes the
 * shared stream. The stream keeps receiving everything and its cursor
 * advances with every event, while the helpers only expose events of the
 * accepted turn (matched by `turn_id`).
 */
final class ChatTurnRuntime
{
    /** @var list<AtpEvent> */
    private array $replay = [];

    private int $replayBytes = 0;

    /**
     * @param  string|null  $chatId  Chat id used for sends; falls back to the stream chat id.
     */
    public function __construct(
        private readonly ChatEventStream $stream,
        private readonly MessageSender $sender,
        private readonly ?string $chatId = null,
        private readonly bool $retainReplay = false,
    ) {}

    /**
     * Waits for live confirmation, then sends the message.
     */
    public function send(string $message): ChatMessageAccepted
    {
        try {
            if (! $this->stream->waitLiveConfirmation(onReplay: $this->retainReplay ? $this->retainReplayEvent(...) : null)) {
                throw new TransportException('The event stream ended or timed out before live confirmation; the message was not sent.');
            }

            return $this->sender->send($this->chatId(), $message);
        } catch (\Throwable $exception) {
            $this->stream->close();

            throw $exception;
        }
    }

    /**
     * Sends the message and streams every event of the accepted turn until
     * its first terminal event. Events of other turns are consumed by the
     * shared cursor but not yielded. The stream keeps receiving everything;
     * on completion the cursor points past the terminal event.
     *
     * @return \Generator<int, AtpEvent, mixed, void>
     */
    public function sendAndStream(string $message): \Generator
    {
        $accepted = $this->send($message);
        $await = new TurnAwait($accepted->turnId);

        foreach ($this->events() as $event) {
            $terminal = $await->push($event);

            if ($event->envelope->turnId === $accepted->turnId) {
                yield $event;
            }

            if ($terminal) {
                return;
            }
        }
    }

    /**
     * Sends the message and awaits the terminal event of the accepted turn.
     */
    public function sendAndWait(string $message): TurnResult
    {
        $accepted = $this->send($message);
        $await = new TurnAwait($accepted->turnId);

        foreach ($this->events() as $event) {
            if ($await->push($event)) {
                break;
            }
        }

        return $await->result($this->chatId(), $this->stream->lastCursor());
    }

    private function retainReplayEvent(AtpEvent $event, int $bytes): void
    {
        $this->replayBytes += $bytes;
        if ($this->replayBytes > 16 * 1024 * 1024) {
            throw new TransportException('Idempotent send replay exceeds 16 MiB; resume using the known turn cursor.');
        }
        $this->replay[] = $event;
    }

    /** @return \Generator<int, AtpEvent, mixed, void> */
    private function events(): \Generator
    {
        // Establish all replay barriers before exposing any executable request
        // to a consumer. A retained result may follow its historical request.
        $resolved = [];
        $terminal = [];
        foreach ($this->replay as $event) {
            if ($event instanceof AgentToolResultEvent) {
                $resolved[$event->callId] = true;
            }
            if (in_array($event->envelope->type, ['turn.completed', 'turn.failed', 'turn.cancelled'], true)) {
                $terminal[$event->envelope->turnId ?? ''] = true;
            }
        }
        foreach ($this->replay as $event) {
            if ($event instanceof AgentToolRequestEvent
                && (isset($resolved[$event->callId]) || isset($terminal[$event->envelope->turnId ?? '']))) {
                continue;
            }
            yield $event;
        }
        $this->replay = [];
        yield from $this->stream;
    }

    private function chatId(): string
    {
        return $this->chatId ?? $this->stream->chatId()
            ?? throw new \LogicException('Chat id is unknown: pass it to ChatTurnRuntime or to ChatEventStream.');
    }
}
