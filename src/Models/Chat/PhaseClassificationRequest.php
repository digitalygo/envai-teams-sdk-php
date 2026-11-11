<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

final readonly class PhaseClassificationRequest
{
    public function __construct(
        public string $requestId,
        public mixed $taskContext,
        public string $phaseId,
        public int $attempt,
        public string $finalOutput,
    ) {
        if ($taskContext === null || $attempt < 1) {
            throw new \InvalidArgumentException('Classification requires non-null task context and a positive attempt.');
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'request_id' => $this->requestId,
            'task_context' => $this->taskContext,
            'phase_id' => $this->phaseId,
            'attempt' => $this->attempt,
            'final_output' => $this->finalOutput,
        ];
    }
}
