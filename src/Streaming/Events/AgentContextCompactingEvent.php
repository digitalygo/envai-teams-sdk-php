<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * Context compaction begins. The follow-up `agent.context.compacted` carries
 * the checkpoint a client should retain for a later restore or fork.
 */
final readonly class AgentContextCompactingEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        array $extra = [],
        public ?string $turnId = null,
        public ?string $reason = null,
        public ?int $contextTokens = null,
        public ?int $thresholdTokens = null,
        public ?int $windowTokens = null,
        public ?float $threshold = null,
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'agent.context.compacting';
    }
}
