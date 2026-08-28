<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Atp;

use Digitalygo\Envai\Streaming\Events\ProtocolViolationEvent;
use Digitalygo\Envai\Streaming\Events\TurnCancelledEvent;
use Digitalygo\Envai\Streaming\Events\TurnCompletedEvent;
use Digitalygo\Envai\Streaming\Events\TurnFailedEvent;
use Digitalygo\Envai\Streaming\Events\Usage;

/**
 * The outcome of one awaited turn.
 *
 * `terminal` is the first turn terminal event whose `turn_id` matches the
 * accepted turn, or null when the stream ended before a terminal. When a
 * terminal payload violates the schema the decoder delivers a
 * ProtocolViolationEvent instead: it is exposed through `terminalViolation`
 * and the terminal stays null because the SDK never fabricates a terminal
 * or its usage. `lastUsage` is the authoritative turn usage carried by the
 * terminal event, or null when it is unavailable.
 */
final readonly class TurnResult
{
    public function __construct(
        public string $chatId,
        public string $turnId,
        public TurnCompletedEvent|TurnCancelledEvent|TurnFailedEvent|null $terminal,
        public ?string $orchestratorText,
        public ?Usage $lastUsage,
        public int $lastSeq,
        public ?ProtocolViolationEvent $terminalViolation = null,
    ) {}
}
