<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class TaskSnapshot implements ArrayHydratable
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $taskId,
        public string $originTurnId,
        public TaskStatus $status,
        public ?string $parentTaskId = null,
        public ?string $targetChatId = null,
        public ?string $output = null,
        public ?string $error = null,
        public ?string $deliveryError = null,
        public ?string $resultTurnId = null,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            taskId: Wire::string($data, 'task_id', 'TaskSnapshot'),
            originTurnId: Wire::string($data, 'origin_turn_id', 'TaskSnapshot'),
            status: TaskStatus::from(Wire::string($data, 'status', 'TaskSnapshot')),
            parentTaskId: Wire::nullableString($data, 'parent_task_id', 'TaskSnapshot'),
            targetChatId: Wire::nullableString($data, 'target_chat_id', 'TaskSnapshot'),
            output: Wire::nullableString($data, 'output', 'TaskSnapshot'),
            error: Wire::nullableString($data, 'error', 'TaskSnapshot'),
            deliveryError: Wire::nullableString($data, 'delivery_error', 'TaskSnapshot'),
            resultTurnId: Wire::nullableString($data, 'result_turn_id', 'TaskSnapshot'),
            extra: Wire::extra($data, ['task_id', 'origin_turn_id', 'status', 'parent_task_id', 'target_chat_id', 'output', 'error', 'delivery_error', 'result_turn_id']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'origin_turn_id' => $this->originTurnId,
            'status' => $this->status->value,
        ] + ($this->parentTaskId === null ? [] : ['parent_task_id' => $this->parentTaskId]) + ($this->targetChatId === null ? [] : ['target_chat_id' => $this->targetChatId]) + ($this->output === null ? [] : ['output' => $this->output]) + ($this->error === null ? [] : ['error' => $this->error]) + ($this->deliveryError === null ? [] : ['delivery_error' => $this->deliveryError]) + ($this->resultTurnId === null ? [] : ['result_turn_id' => $this->resultTurnId]) + $this->extra;
    }

    public function isTerminal(): bool
    {
        return $this->status !== TaskStatus::Running;
    }
}
