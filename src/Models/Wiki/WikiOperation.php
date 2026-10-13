<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Wiki;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use Digitalygo\Envai\Models\Usage\BillingState;
use Digitalygo\Envai\Models\Usage\UsageCost;

final readonly class WikiOperation implements ArrayHydratable
{
    /**
     * @param  array<string, PhaseResult>  $phases
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $operationId,
        public string $workspaceId,
        public string $kind,
        public string $status,
        public ?string $phase,
        public array $phases,
        public ?string $startedAt,
        public ?string $finishedAt,
        public BatchSizes $batchSizes,
        public BillingState $billing,
        public UsageCost $usageCost,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            operationId: Wire::string($data, 'operation_id', self::class),
            workspaceId: Wire::string($data, 'workspace_id', self::class),
            kind: Wire::string($data, 'kind', self::class),
            status: Wire::string($data, 'status', self::class),
            phase: Wire::nullableString($data, 'phase', self::class),
            phases: self::phasesFromArray($data),
            startedAt: Wire::nullableString($data, 'started_at', self::class),
            finishedAt: Wire::nullableString($data, 'finished_at', self::class),
            batchSizes: BatchSizes::fromArray(Wire::objectArray($data, 'batch_sizes', self::class)),
            billing: BillingState::fromArray(Wire::objectArray($data, 'billing', self::class)),
            usageCost: UsageCost::fromArray(Wire::objectArray($data, 'usage_cost', self::class)),
            extra: Wire::extra($data, ['operation_id', 'workspace_id', 'kind', 'status', 'phase', 'phases', 'started_at', 'finished_at', 'batch_sizes', 'billing', 'usage_cost']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'operation_id' => $this->operationId,
            'workspace_id' => $this->workspaceId,
            'kind' => $this->kind,
            'status' => $this->status,
            'phase' => $this->phase,
            'phases' => $this->phases === [] ? new \stdClass : array_map(static fn (PhaseResult $item): array => $item->toArray(), $this->phases),
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'batch_sizes' => $this->batchSizes->toArray(),
            'billing' => $this->billing->toArray(),
            'usage_cost' => $this->usageCost->toArray(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, PhaseResult>
     */
    private static function phasesFromArray(array $data): array
    {
        $values = Wire::presentOrFail($data, 'phases', self::class);
        if (! is_array($values)) {
            throw new \InvalidArgumentException('Invalid phases.');
        }
        $result = [];
        foreach ($values as $key => $value) {
            if (! is_array($value)) {
                throw new \InvalidArgumentException('Invalid phases item.');
            }
            if (! is_string($key)) {
                throw new \InvalidArgumentException('Invalid phase name.');
            }
            $result[$key] = PhaseResult::fromArray(Wire::stringKeyed($value, 'phase', self::class));
        }

        return $result;
    }
}
