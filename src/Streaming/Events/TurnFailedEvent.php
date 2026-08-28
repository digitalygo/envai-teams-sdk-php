<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * The turn failed. `code` is a machine-readable failure code and `message`
 * a human-readable failure message.
 */
final readonly class TurnFailedEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $turnId,
        public string $code,
        public string $message,
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
        return 'turn.failed';
    }
}
