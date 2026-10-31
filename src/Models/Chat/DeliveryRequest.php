<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

final readonly class DeliveryRequest
{
    public function __construct(
        public string $workspaceId,
        public string $eventId,
        public string $originTurnId,
        public string $taskId,
        public DeliveryOutcome $status,
        public string $output,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'event_id' => $this->eventId,
            'origin_turn_id' => $this->originTurnId,
            'task_id' => $this->taskId,
            'status' => $this->status->value,
            'output' => $this->output,
        ];
    }
}
