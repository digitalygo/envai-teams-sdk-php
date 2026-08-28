<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * The orchestrator relays the outcome of an escalation to the subagent,
 * resuming it or fail-closing the branch.
 */
final readonly class AgentEscalationResultEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $escalationId,
        public bool $approve,
        public string $reason,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'agent.escalation.result';
    }
}
