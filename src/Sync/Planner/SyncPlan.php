<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Planner;

use Digitalygo\Envai\Sync\State\ResourceType;

/**
 * Immutable plan produced by the SyncPlanner for one connection and one
 * compilation. The operations are ordered deterministically (tools,
 * agents, teams by manifest order, then orphans by type and local key),
 * which makes the plan hash stable across identical inputs.
 *
 * The plan never holds a lock: planning is read-only and callers acquire
 * the lock only to keep plan + apply atomic (see DefinitionsService).
 */
final readonly class SyncPlan
{
    /**
     * @param  list<SyncOperation>  $operations
     */
    public function __construct(
        public string $connection,
        public string $manifestHash,
        public string $planHash,
        public \DateTimeImmutable $plannedAt,
        public SyncOptions $options,
        public array $operations,
    ) {}

    /**
     * Operations the policy blocked (action None with blocking true).
     *
     * @return list<SyncOperation>
     */
    public function blocking(): array
    {
        return array_values(array_filter(
            $this->operations,
            static fn (SyncOperation $op): bool => $op->blocking,
        ));
    }

    /**
     * Operations the reconciler may execute: action is not None and the
     * operation is not blocked.
     *
     * @return list<SyncOperation>
     */
    public function executable(): array
    {
        return array_values(array_filter(
            $this->operations,
            static fn (SyncOperation $op): bool => ! $op->blocking && $op->action !== SyncAction::None,
        ));
    }

    /**
     * Operations for the given resource type, in plan order.
     *
     * @return list<SyncOperation>
     */
    public function byResourceType(ResourceType $type): array
    {
        return array_values(array_filter(
            $this->operations,
            static fn (SyncOperation $op): bool => $op->type === $type,
        ));
    }

    /**
     * Whether the plan covers only a subset of resource kinds (the
     * `--only` option). A partial-scope apply never marks the overall
     * manifest ready.
     */
    public function isPartialScope(): bool
    {
        return $this->options->scope !== SyncScope::All;
    }

    /**
     * Serializes the plan to the stable machine-readable shape used by
     * the CLI `--json` output: the plan header, the applied options, one
     * section per resource kind in deterministic order and the blocking
     * operations under `conflicts`. Dates are ISO 8601; enum values are
     * serialized as their string values; plan_format is always 1.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'plan_format' => 1,
            'connection' => $this->connection,
            'manifest_hash' => $this->manifestHash,
            'plan_hash' => $this->planHash,
            'planned_at' => $this->plannedAt->format(\DateTimeInterface::ATOM),
            'scope' => $this->options->scope->value,
            'options' => [
                'adopt' => $this->options->adopt,
                'overwrite_drift' => $this->options->overwriteDrift,
                'recreate_missing' => $this->options->recreateMissing,
                'prune' => $this->options->prune,
                'fail_on_drift' => $this->options->failOnDrift,
                'dry_run' => $this->options->dryRun,
            ],
            'tools' => array_map(static fn (SyncOperation $op): array => $op->toArray(), $this->byResourceType(ResourceType::Tool)),
            'agents' => array_map(static fn (SyncOperation $op): array => $op->toArray(), $this->byResourceType(ResourceType::Agent)),
            'teams' => array_map(static fn (SyncOperation $op): array => $op->toArray(), $this->byResourceType(ResourceType::Team)),
            'conflicts' => array_map(static fn (SyncOperation $op): array => $op->toArray(), $this->blocking()),
        ];
    }
}
