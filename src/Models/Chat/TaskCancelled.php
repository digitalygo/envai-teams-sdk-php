<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class TaskCancelled implements ArrayHydratable
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $taskId,
        public TaskStatus $status,
        public bool $subtree,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            taskId: Wire::string($data, 'task_id', 'TaskCancelled'),
            status: TaskStatus::from(Wire::string($data, 'status', 'TaskCancelled')),
            subtree: Wire::bool($data, 'subtree', 'TaskCancelled'),
            extra: Wire::extra($data, ['task_id', 'status', 'subtree']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'status' => $this->status->value,
            'subtree' => $this->subtree,
        ] + $this->extra;
    }
}
