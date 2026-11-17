<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Common;

use Digitalygo\Envai\Models\Internal\Wire;

final readonly class CleanupIntentState implements ArrayHydratable
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        public string $id,
        public string $kind,
        public string $status,
        public int $attempts,
        public ?string $deadlineAt,
        public ?string $startedAt,
        public ?string $finishedAt,
        public ?string $lastErrorCode,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            Wire::string($data, 'id', 'CleanupIntentState'),
            Wire::string($data, 'kind', 'CleanupIntentState'),
            Wire::string($data, 'status', 'CleanupIntentState'),
            Wire::int($data, 'attempts', 'CleanupIntentState'),
            Wire::nullableString($data, 'deadline_at', 'CleanupIntentState'),
            Wire::nullableString($data, 'started_at', 'CleanupIntentState'),
            Wire::nullableString($data, 'finished_at', 'CleanupIntentState'),
            Wire::nullableString($data, 'last_error_code', 'CleanupIntentState'),
            Wire::extra($data, ['id', 'kind', 'status', 'attempts', 'deadline_at', 'started_at', 'finished_at', 'last_error_code']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge([
            'id' => $this->id,
            'kind' => $this->kind,
            'status' => $this->status,
            'attempts' => $this->attempts,
            'deadline_at' => $this->deadlineAt,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'last_error_code' => $this->lastErrorCode,
        ], $this->extra);
    }
}
