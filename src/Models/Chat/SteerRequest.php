<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

/**
 * Payload to record guidance for an active turn. `target_agent_id` is
 * optional and targets the orchestrator when omitted or null.
 */
final readonly class SteerRequest
{
    public function __construct(
        public string $text,
        public ?string $targetAgentId = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = ['text' => $this->text];

        if ($this->targetAgentId !== null) {
            $payload['target_agent_id'] = $this->targetAgentId;
        }

        return $payload;
    }
}
