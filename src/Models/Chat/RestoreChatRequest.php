<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

/**
 * Payload to restore a chat from client held context. `orchestratorContext`
 * lists the compacted context first (kind `compacted`) followed by
 * orchestrator assistant messages; it replaces in-memory history and
 * schedules asynchronous best-effort persistence.
 */
final readonly class RestoreChatRequest
{
    /**
     * @param  list<OrchestratorContextItem>  $orchestratorContext
     */
    public function __construct(
        public array $orchestratorContext,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'orchestrator_context' => array_map(
                static fn (OrchestratorContextItem $item): array => $item->toArray(),
                $this->orchestratorContext,
            ),
        ];
    }
}
