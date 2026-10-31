<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class DelegationAccepted implements ArrayHydratable
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $taskId,
        public string $targetChatId,
        public string $turnId,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            taskId: Wire::string($data, 'task_id', 'DelegationAccepted'),
            targetChatId: Wire::string($data, 'target_chat_id', 'DelegationAccepted'),
            turnId: Wire::string($data, 'turn_id', 'DelegationAccepted'),
            extra: Wire::extra($data, ['task_id', 'target_chat_id', 'turn_id']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'task_id' => $this->taskId,
            'target_chat_id' => $this->targetChatId,
            'turn_id' => $this->turnId,
        ] + $this->extra;
    }
}
