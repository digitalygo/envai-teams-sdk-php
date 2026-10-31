<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * The agent finished its work. `agent.done` alone does not close the turn:
 * only the turn.* terminal events do.
 */
final readonly class AgentDoneEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $status,
        public string $summary,
        public Usage $usage,
        public int $durationMs,
        array $extra = [],
        public ?string $requestedModel = null,
        public ?string $model = null,
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'agent.done';
    }
}
