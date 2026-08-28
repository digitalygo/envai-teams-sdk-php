<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * The active turn was cancelled (for example `user_cancelled`). The chat
 * stays idle and accepts new messages.
 */
final readonly class TurnCancelledEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $turnId,
        public string $reason,
        public Usage $usage,
        public float $cost,
        public Usage $chatUsage,
        public float $chatCost,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'turn.cancelled';
    }
}
