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
}
