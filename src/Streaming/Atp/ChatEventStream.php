<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Atp;

use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Contracts\EventStreamConnection;
use Digitalygo\Envai\Exceptions\OperationCancelledException;
use Digitalygo\Envai\Exceptions\ReplayGapException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Streaming\Events\AtpEvent;
use Digitalygo\Envai\Streaming\Events\PingEvent;
use Digitalygo\Envai\Streaming\Sse\SseParser;
use Psr\Log\LoggerInterface;

/**
 * The typed ATP event stream with cursor semantics.
 *
 * The stream is a single reader: one instance may be consumed once by one
 * consumer (there is no internal fan-out). Iterate it with `foreach` or call
 * getIterator() directly; every decoded event is delivered in wire order and
 * the cursor advances together with each delivered event. `ping` events are
 * delivered as control events but never advance the cursor.
 *
 * Replay algorithm (foundation doc section 12):
 *
 * 1. Open the stream with the initial cursor, default 0.
 * 2. Read bytes through SseParser and decode each frame.
 * 3. Deliver `ping` as a control event; it never advances the cursor.
 * 4. Silently skip duplicate non-ping events with `seq <= cursor`.
 * 5. On `seq > cursor + 1`, close the connection and reopen with
 *    `after=cursor` through the injected connection factory (the transport
 *    is the caller's concern), up to `maxReconnectAttempts` with backoff
 *    and jitter between failed attempts.
 * 6. If the replay still starts beyond `cursor + 1`, throw
 *    ReplayGapException with the missing range; the client cannot rebuild
 *    evicted events.
 * 7. Deliver the event with the cursor advanced to its seq.
 *
 * A clean EOF ends the iteration. A cancelled token is checked on every
 * loop iteration and raises OperationCancelledException mid-stream; the
 * connection is closed deterministically when the generator completes or is
 * abandoned.
 *
 * The chat id is optional at construction (needed by the turn helpers to
 * send messages): when absent it is learned from the first decoded envelope.
 *
 * @implements \IteratorAggregate<int, AtpEvent>
 */
final class ChatEventStream implements \IteratorAggregate
{
    private SseParser $parser;

    private EventStreamConnection $connection;

    private int $cursor;

    private bool $liveConfirmed = false;

    private bool $recovering = false;

    private bool $replayPending = false;

    private int $gapSeq = 0;

    private int $reconnectAttempts = 0;

    private bool $iterated = false;

    private bool $closed = false;

    private ?string $learnedChatId = null;

    /**
     * @param  (\Closure(int): EventStreamConnection)|null  $connectionFactory  Rebuilds the connection for replay; receives the `after` cursor.
     * @param  positive-int  $maxReconnectAttempts  Reconnect budget per detected gap.
     */
    public function __construct(
        EventStreamConnection $connection,
        private readonly ?CancellationToken $token = null,
        private readonly AtpEventDecoder $decoder = new AtpEventDecoder,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $initialCursor = 0,
        private readonly ?string $chatId = null,
        private readonly ?\Closure $connectionFactory = null,
        private readonly int $maxReconnectAttempts = 2,
        private readonly int $reconnectBaseDelayMs = 250,
        private readonly int $jitterMs = 50,
        private readonly int $readChunkSize = 8192,
    ) {
        $this->connection = $connection;
        $this->cursor = max(0, $initialCursor);
        $this->parser = new SseParser;
    }

    /**
     * Consumes the stream from the current cursor until EOF, cancellation or
     * a terminal error. The returned generator owns the read lifecycle and
     * closes the connection when it completes or is abandoned.
     *
     * @return \Generator<int, AtpEvent, mixed, void>
     */
    public function getIterator(): \Generator
    {
        if ($this->iterated) {
            throw new \LogicException('ChatEventStream is single-reader: each instance can be consumed once.');
        }

        $this->iterated = true;

        return $this->iterate();
    }

    /**
     * @return \Generator<int, AtpEvent, mixed, void>
     */
    private function iterate(): \Generator
    {
        $inputFinished = false;
        try {
            while (true) {
                $this->token?->throwIfCancelled();

                if ($this->closed) {
                    return;
                }

                while (($frame = $this->parser->nextFrame()) !== null) {
                    $this->token?->throwIfCancelled();

                    $event = $this->decoder->decode($frame->data);
                    $this->learnChatId($event);

                    if ($this->recovering) {
                        $this->recovering = false;
                        $this->reconnectAttempts = 0;
                        $this->logger?->info('ATP replay connection established after seq {cursor}.', ['cursor' => $this->cursor]);
                    }

                    if ($event instanceof PingEvent) {
                        $this->liveConfirmed = true;

                        yield $event;

                        continue;
                    }

                    $seq = $event->envelope->seq;

                    if ($seq <= $this->cursor) {
                        continue;
                    }

                    if ($this->replayPending) {
                        $this->replayPending = false;

                        if ($seq > $this->cursor + 1) {
                            $this->connection->close();

                            throw new ReplayGapException(
                                sprintf(
                                    'Replay after seq %d starts at seq %d: events %d..%d are no longer retained by the gateway.',
                                    $this->cursor,
                                    $seq,
                                    $this->cursor + 1,
                                    $seq - 1,
                                ),
                                missingFrom: $this->cursor + 1,
                                missingTo: $seq - 1,
                            );
                        }
                    }

                    if ($seq > $this->cursor + 1) {
                        $this->startRecovery($seq);

                        continue 2;
                    }

                    $this->cursor = $seq;

                    yield $event;
                }

                $bytes = $this->connection->read($this->readChunkSize);

                if ($bytes === null) {
                    if (! $inputFinished) {
                        $this->parser->finishInput();
                        $inputFinished = true;

                        continue;
                    }
                    $this->token?->throwIfCancelled();

                    if ($this->recovering) {
                        $this->connection->close();
                        $this->reconnectAttempts++;

                        if ($this->reconnectAttempts >= $this->maxReconnectAttempts) {
                            throw new TransportException(sprintf(
                                'Replay recovery failed: %d reconnect attempt(s) delivered no events after the gap at seq %d.',
                                $this->reconnectAttempts,
                                $this->gapSeq,
                            ));
                        }

                        $this->backoff();
                        $this->openReconnectConnection();

                        continue;
                    }

                    return;
                }

                $inputFinished = false;
                $this->parser->feed($bytes);

            }
        } finally {
            $this->close();
        }
    }

    /**
     * The cursor of the last delivered replayable event. `ping` never moves
     * it, and events consumed while awaiting live confirmation advance it.
     */
    public function lastCursor(): int
    {
        return $this->cursor;
    }

    /**
     * Whether the current connection has delivered at least one `ping`,
     * proving the gateway registered the live subscription.
     */
    public function isLiveConfirmed(): bool
    {
        return $this->liveConfirmed;
    }

    /**
     * Blocks reading until the first `ping`, EOF or
     * the timeout elapses, whichever comes first.
     *
     * This is the send-message race workaround: the Envai alpha registers
     * the live subscription only after replay, so a message sent right after
     * connect could lose the `turn.started`. Events consumed here (typically
     * pre-turn replay events) are considered delivered to the stream
     * controller: they advance the cursor and are not re-emitted by the
     * iterator, so the turn helpers can correlate by `turn_id` without
     * seeing sequence gaps.
     *
     * With a blocking transport the timeout is only observed between reads:
     * enforce it promptly by cancelling the connection from another context.
     */
    public function waitLiveConfirmation(?float $timeoutSeconds = 60, ?callable $onReplay = null): bool
    {
        if ($this->liveConfirmed) {
            return true;
        }

        $deadline = microtime(true) + max(0.0, $timeoutSeconds ?? 60.0);

        while (true) {
            $this->token?->throwIfCancelled();

            if ($this->closed || microtime(true) >= $deadline) {
                return false;
            }

            $bytes = $this->connection->read($this->readChunkSize);

            if ($bytes === null) {
                return false;
            }

            $this->parser->feed($bytes);

            while (($frame = $this->parser->nextFrame()) !== null) {
                $event = $this->decoder->decode($frame->data);
                $this->learnChatId($event);

                if ($event instanceof PingEvent) {
                    $this->liveConfirmed = true;

                    return true;
                }

                // Historical terminal events are replay, not proof that the
                // new live subscription is ready. Continue until the ping.
                $previousCursor = $this->cursor;
                $this->advanceCursor($event);
                if ($onReplay !== null && $this->cursor > $previousCursor) {
                    $onReplay($event, strlen($frame->data));
                }
            }
        }
    }

    /**
     * The chat id of this stream: the constructor value when provided,
     * otherwise the id learned from the first decoded envelope, or null
     * before any event has been decoded.
     */
    public function chatId(): ?string
    {
        return $this->chatId ?? $this->learnedChatId;
    }

    /**
     * Closes the underlying connection deterministically. Idempotent; also
     * invoked when the iterator completes or is abandoned.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->connection->close();
    }

    /**
     * Starts recovery for a detected sequence gap: closes the current
     * connection and reopens it through the factory with `after=cursor`.
     */
    private function startRecovery(int $gapSeq): void
    {
        $this->logger?->warning('ATP sequence gap: expected seq {expected}, got {seq}; reopening with after={after}.', [
            'expected' => $this->cursor + 1,
            'seq' => $gapSeq,
            'after' => $this->cursor,
        ]);

        $this->gapSeq = $gapSeq;
        $this->recovering = true;
        $this->replayPending = true;
        $this->reconnectAttempts = 0;
        $this->liveConfirmed = false;
        $this->connection->close();

        $this->openReconnectConnection();
    }

    /**
     * Rebuilds the connection through the injected factory. Without a
     * factory the gap is unrecoverable and ReplayGapException is raised.
     */
    private function openReconnectConnection(): void
    {
        if ($this->connectionFactory === null) {
            throw new ReplayGapException(
                sprintf(
                    'Sequence gap detected (expected seq %d, got %d) but no connection factory was provided to replay from after=%d.',
                    $this->cursor + 1,
                    $this->gapSeq,
                    $this->cursor,
                ),
                missingFrom: $this->cursor + 1,
                missingTo: $this->gapSeq - 1,
            );
        }

        try {
            $this->connection = ($this->connectionFactory)($this->cursor);
        } catch (\Throwable $e) {
            throw new TransportException(sprintf('Reconnect factory failed while replaying after seq %d: %s', $this->cursor, $e->getMessage()), previous: $e);
        }

        $this->parser = new SseParser;
    }

    private function backoff(): void
    {
        $delay = $this->reconnectBaseDelayMs + ($this->jitterMs > 0 ? random_int(0, $this->jitterMs) : 0);

        if ($delay > 0) {
            usleep($delay * 1000);
        }
    }

    private function advanceCursor(AtpEvent $event): void
    {
        $seq = $event->envelope->seq;

        if ($seq > $this->cursor) {
            $this->cursor = $seq;
        }
    }

    private function learnChatId(AtpEvent $event): void
    {
        if ($this->learnedChatId === null) {
            $this->learnedChatId = $event->envelope->chatId;
        }
    }
}
