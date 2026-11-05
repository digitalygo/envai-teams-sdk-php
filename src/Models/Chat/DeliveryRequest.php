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
        public ?bool $strictModel = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $data = [
            'workspace_id' => $this->workspaceId,
            'event_id' => $this->eventId,
            'origin_turn_id' => $this->originTurnId,
            'task_id' => $this->taskId,
            'status' => $this->status->value,
            'output' => $this->output,
        ];
        if ($this->originTurnId === '') {
            unset($data['origin_turn_id']);
        }
        if ($this->strictModel !== null) {
            $data['strict_model'] = $this->strictModel;
        }

        return $data;
    }
}
