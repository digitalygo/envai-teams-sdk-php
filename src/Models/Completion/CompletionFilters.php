<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Completion;

/**
 * Query filters for the completion listing endpoint (newest first).
 *
 * `startedAtFrom` and `startedAtTo` map to the `started_at[from]` and
 * `started_at[to]` query parameters (inclusive, ISO 8601) and stay strings
 * (wire-faithful). A null filter is omitted from the query string; `status`
 * serializes through the enum value.
 */
final readonly class CompletionFilters
{
    public function __construct(
        public ?CompletionStatus $status = null,
        public ?string $modelOrchestrator = null,
        public ?string $startedAtFrom = null,
        public ?string $startedAtTo = null,
        public ?int $perPage = null,
        public ?int $page = null,
    ) {
        if ($page !== null && $page < 1) {
            throw new \InvalidArgumentException('page must be positive.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];

        if ($this->status !== null) {
            $payload['status'] = $this->status->value;
        }

        if ($this->modelOrchestrator !== null) {
            $payload['model_orchestrator'] = $this->modelOrchestrator;
        }

        if ($this->startedAtFrom !== null) {
            $payload['started_at[from]'] = $this->startedAtFrom;
        }

        if ($this->startedAtTo !== null) {
            $payload['started_at[to]'] = $this->startedAtTo;
        }

        if ($this->perPage !== null) {
            $payload['per_page'] = $this->perPage;
        }

        if ($this->page !== null) {
            $payload['page'] = $this->page;
        }

        return $payload;
    }
}
