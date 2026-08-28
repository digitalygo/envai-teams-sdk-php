<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * The orchestrator asks the user for an approval. `originAgentId` is the
 * orchestrator itself for self-initiated approvals, or the escalating
 * subagent when the orchestrator escalates on its behalf.
 */
final readonly class ApprovalRequestEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $approvalId,
        public string $originAgentId,
        public string $toolName,
        public JsonObject $args,
        public string $reason,
        public string $deadline,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'approval.request';
    }
}
