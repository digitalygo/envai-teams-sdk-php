<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * Context compaction finished: `newContext` is the summarized context that
 * replaces the pruned history and is the checkpoint for fork and restore.
 */
final readonly class AgentContextCompactedEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $newContext,
        array $extra = [],
        public ?string $turnId = null,
        public ?int $contextTokens = null,
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'agent.context.compacted';
    }
}
