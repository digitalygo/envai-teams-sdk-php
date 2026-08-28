<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * A subagent needs a decision and suspends (state `waiting_decision`) until
 * the orchestrator resolves it internally, denies it, or surfaces it to the
 * user as an approval request.
 */
final readonly class AgentEscalateEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $escalationId,
        public string $agentId,
        public string $reason,
        public string $toolName,
        public JsonObject $args,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'agent.escalate';
    }
}
