<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * The full final reasoning of the agent. Never aggregated into the
 * user-facing text.
 */
final readonly class AgentReasoningDoneEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $text,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'agent.reasoning.done';
    }
}
