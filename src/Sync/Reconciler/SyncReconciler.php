<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Reconciler;

use Digitalygo\Envai\Definitions\Manifest\CanonicalManifestHasher;
use Digitalygo\Envai\Definitions\Manifest\DefinitionManifest;
use Digitalygo\Envai\Exceptions\ApiException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Sync\Diff\DefinitionDiff;
use Digitalygo\Envai\Sync\Planner\PayloadNormalizer;
use Digitalygo\Envai\Sync\Planner\SyncAction;
use Digitalygo\Envai\Sync\Planner\SyncOperation;
use Digitalygo\Envai\Sync\Planner\SyncPlan;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Sync\State\SyncLock;
use Digitalygo\Envai\Sync\State\SyncStateStore;

/**
 * Executes a SyncPlan against the remote API through a RemoteWriter,
 * updating the state store and producing a SyncReport.
 *
 * Behavior is binding from the project document (sections 19.4, 20.3,
 * 20.4 and 21):
 *
 * - upserts run tools, then agents, then teams; prune deletions run
 *   after upserts, teams, then agents, then tools;
 * - a dry run mutates nothing and never marks the manifest ready;
 * - every successful mutation saves the resource state (remote id,
 *   remote name, desired hash, last-applied payload, marker, applied
 *   status, synchronization timestamp);
 * - adoption registers the remote payload as last applied (not the
 *   desired one) with the desired hash, so the next plan classifies an
 *   update; with the overwrite-drift option the update mutation follows
 *   in the same apply;
 * - a TransportException around a mutation is never retried blindly:
 *   the reconciler reads the remote back once. Equal payload means the
 *   mutation landed (succeeded); anything else fails closed with an
 *   ambiguous state and blocks the apply, never marking the manifest
 *   ready;
 * - prune only touches resources present in the state store (never an
 *   unmanaged remote); a blocked deletion is reported, not circumvented;
 * - the manifest is marked ready only when nothing was blocked, failed
 *   or ambiguous, the scope is complete and the apply was not a dry run;
 * - the whole apply runs under the connection lock, released in a
 *   finally block. An optional pre-acquired lock can be handed in by a
 *   caller that already holds it (DefinitionsService); in that case the
 *   caller owns the release.
 */
final class SyncReconciler
{
    private readonly CanonicalManifestHasher $hasher;

    public function __construct(
        private readonly RemoteWriter $writer,
        private readonly SyncStateStore $store,
        ?CanonicalManifestHasher $hasher = null,
    ) {
        $this->hasher = $hasher ?? new CanonicalManifestHasher;
    }

    public function apply(SyncPlan $plan, DefinitionManifest $manifest, ?SyncLock $lock = null): SyncReport
    {
        $ownsLock = $lock === null;
        $lock ??= $this->store->acquireLock($plan->connection);

        try {
            return $this->applyLocked($plan);
        } finally {
            if ($ownsLock) {
                $lock->release();
            }
        }
    }

    /**
     * Executes the plan under the caller-provided lock or a freshly
     * acquired one. The lock is released in a finally block only when
     * this method acquired it; otherwise the caller owns the release.
     */
    private function applyLocked(SyncPlan $plan): SyncReport
    {
        $connection = $plan->connection;
        $startedAt = new \DateTimeImmutable;

        $agentIds = $this->agentIdMap($connection);

        if ($plan->options->dryRun) {
            $results = [];

            foreach ($plan->operations as $op) {
                $results[] = $this->resultFor($op, SyncOperationOutcome::Pending);
            }

            return $this->buildReport($plan, $startedAt, SyncReportStatus::DryRun, $results, manifestReady: false);
        }

        $executed = [];
        $interrupted = false;
        $unexpected = false;

        foreach ($this->orderOperations($plan) as $op) {
            if ($interrupted) {
                continue;
            }

            try {
                $result = $this->executeOp($connection, $plan, $op, $agentIds);
            } catch (\Throwable $exception) {
                $result = new SyncOperationResult($op->id, SyncOperationOutcome::Failed, error: $exception->getMessage());
                $unexpected = true;
                $interrupted = true;
            }

            $executed[$op->id] = $result;

            if ($result->outcome === SyncOperationOutcome::Ambiguous) {
                $interrupted = true;
            } elseif ($result->outcome === SyncOperationOutcome::Failed && $op->action !== SyncAction::Delete) {
                $interrupted = true;
            }
        }

        $results = [];

        foreach ($plan->operations as $op) {
            if (isset($executed[$op->id])) {
                $results[] = $executed[$op->id];
            } else {
                $results[] = $this->resultFor($op, SyncOperationOutcome::Skipped);
            }
        }

        $status = $this->computeStatus($plan, $results, $unexpected);

        $manifestReady = false;

        if ($status === SyncReportStatus::Succeeded && ! $plan->isPartialScope()) {
            $this->store->markManifestReady($connection, $plan->manifestHash);
            $manifestReady = true;
        }

        return $this->buildReport($plan, $startedAt, $status, $results, $manifestReady);
    }

    /**
     * Live map of local agent key to remote id, seeded from the state
     * store and kept current as agent operations succeed during the
     * apply. Teams are rewritten against this map before mutation.
     *
     * @return array<string, string>
     */
    private function agentIdMap(string $connection): array
    {
        $agentIds = [];

        foreach ($this->store->resources($connection) as $state) {
            if ($state->type === ResourceType::Agent && $state->remoteId !== null) {
                $agentIds[$state->localKey] = $state->remoteId;
            }
        }

        return $agentIds;
    }

    /**
     * Executes one operation, catching ApiException and TransportException
     * per operation. Any other Throwable bubbles to the caller, which
     * records a failed result and stops the apply (partial report).
     *
     * @param  array<string, string>  $agentIds  Live local agent key to remote id map.
     */
    private function executeOp(string $connection, SyncPlan $plan, SyncOperation $op, array &$agentIds): SyncOperationResult
    {
        return match ($op->action) {
            SyncAction::Create, SyncAction::Recreate => $this->create($connection, $op, $agentIds),
            SyncAction::Update => $this->update($connection, $op, $agentIds),
            SyncAction::Adopt => $this->adopt($connection, $plan, $op, $agentIds),
            SyncAction::Delete => $this->delete($connection, $op),
            SyncAction::None => $this->resultFor($op, SyncOperationOutcome::Skipped),
        };
    }

    /**
     * @param  array<string, string>  $agentIds
     */
    private function create(string $connection, SyncOperation $op, array &$agentIds): SyncOperationResult
    {
        $desired = $op->desiredPayload ?? [];

        try {
            $result = match ($op->type) {
                ResourceType::Tool => $this->writer->createTool($desired),
                ResourceType::Agent => $this->writer->createAgent($desired),
                ResourceType::Team => $this->writer->createTeam($this->rewriteTeamPayload($desired, $agentIds)),
            };
        } catch (TransportException $exception) {
            return $this->reconcileAmbiguous($connection, $op, $exception, $agentIds);
        } catch (ApiException $exception) {
            return $this->failed($op, $exception);
        }

        $this->saveApplied($connection, $op, $result, $desired, $agentIds);

        return $this->succeeded($op, $result);
    }

    /**
     * @param  array<string, string>  $agentIds
     */
    private function update(string $connection, SyncOperation $op, array &$agentIds): SyncOperationResult
    {
        $desired = $op->desiredPayload ?? [];

        try {
            $result = match ($op->type) {
                ResourceType::Tool => $this->writer->updateTool($op->remoteName ?? $op->localKey, $desired),
                ResourceType::Agent => $this->writer->updateAgent($op->remoteId ?? '', $desired),
                ResourceType::Team => $this->writer->updateTeam($op->remoteId ?? '', $this->rewriteTeamPayload($desired, $agentIds)),
            };
        } catch (TransportException $exception) {
            return $this->reconcileAmbiguous($connection, $op, $exception, $agentIds);
        } catch (ApiException $exception) {
            return $this->failed($op, $exception);
        }

        $this->saveApplied($connection, $op, $result, $desired, $agentIds);

        return $this->succeeded($op, $result);
    }

    /**
     * Takes an unmanaged remote into management: the state store records
     * the remote payload as last applied (never the desired one) with the
     * desired hash, so the next plan classifies an update. With the
     * overwrite-drift option the update mutation immediately follows in
     * the same apply.
     *
     * @param  array<string, string>  $agentIds
     */
    private function adopt(string $connection, SyncPlan $plan, SyncOperation $op, array &$agentIds): SyncOperationResult
    {
        $remote = $op->remotePayload ?? [];
        $desired = $op->desiredPayload ?? [];
        $remoteId = $this->stringValue($remote, 'id') ?? $op->remoteId;
        $remoteName = $this->stringValue($remote, 'name') ?? $op->remoteName ?? $op->localKey;
        $remoteMarker = $this->stringValue($remote, 'updated_at') ?? $op->remoteMarker;

        $this->store->saveResource(new ManagedResourceState(
            connection: $connection,
            type: $op->type,
            localKey: $op->localKey,
            remoteId: $remoteId,
            remoteName: $remoteName,
            desiredHash: $this->hasher->resourceHash($op->type->value, $op->localKey, $desired),
            lastAppliedPayload: $remote,
            remoteMarker: $remoteMarker,
            synchronizedAt: new \DateTimeImmutable,
            status: ManagedResourceStatus::Applied,
            metadata: [],
        ));

        if ($op->type === ResourceType::Agent && $remoteId !== null) {
            $agentIds[$op->localKey] = $remoteId;
        }

        if (! $plan->options->overwriteDrift) {
            return new SyncOperationResult($op->id, SyncOperationOutcome::Succeeded, $remoteId, $remoteName, $remoteMarker);
        }

        try {
            $result = match ($op->type) {
                ResourceType::Tool => $this->writer->updateTool($remoteName, $desired),
                ResourceType::Agent => $this->writer->updateAgent($remoteId ?? '', $desired),
                ResourceType::Team => $this->writer->updateTeam($remoteId ?? '', $this->rewriteTeamPayload($desired, $agentIds)),
            };
        } catch (TransportException $exception) {
            return $this->reconcileAmbiguous($connection, $op, $exception, $agentIds);
        } catch (ApiException $exception) {
            return $this->failed($op, $exception);
        }

        $this->saveApplied($connection, $op, $result, $desired, $agentIds);

        return $this->succeeded($op, $result);
    }

    private function delete(string $connection, SyncOperation $op): SyncOperationResult
    {
        $state = $this->store->getResource($connection, $op->type, $op->localKey);

        if ($state === null) {
            return new SyncOperationResult(
                $op->id,
                SyncOperationOutcome::Failed,
                error: sprintf('Refusing to delete unmanaged remote "%s": no state record exists.', $op->id),
            );
        }

        try {
            if ($op->type === ResourceType::Tool) {
                $this->writer->deleteTool($state->remoteName ?? $state->localKey);
            } elseif ($op->type === ResourceType::Agent) {
                $this->writer->deleteAgent($this->remoteIdOrFail($op, $state));
            } else {
                $this->writer->deleteTeam($this->remoteIdOrFail($op, $state));
            }
        } catch (TransportException $exception) {
            return $this->reconcileAmbiguousDelete($connection, $op, $state, $exception);
        } catch (ApiException $exception) {
            return $this->failed($op, $exception);
        }

        $this->store->deleteResource($connection, $op->type, $op->localKey);

        return new SyncOperationResult($op->id, SyncOperationOutcome::Succeeded);
    }

    /**
     * Post-ambiguity reconciliation after a TransportException on a
     * create/update mutation: read the remote back once, never retry the
     * mutation. A payload equal to the desired one proves the mutation
     * landed and is recorded as applied; anything else is ambiguous and
     * blocks the apply (fail-closed).
     *
     * @param  array<string, string>  $agentIds
     */
    private function reconcileAmbiguous(string $connection, SyncOperation $op, TransportException $exception, array $agentIds): SyncOperationResult
    {
        $nameOrId = match ($op->type) {
            ResourceType::Tool => $op->remoteName ?? $op->localKey,
            ResourceType::Agent, ResourceType::Team => $op->remoteId ?? '',
        };

        try {
            $found = $this->writer->readRemote($op->type->value, $nameOrId);
        } catch (\Throwable) {
            $this->markAmbiguous($connection, $op, null);

            return new SyncOperationResult($op->id, SyncOperationOutcome::Ambiguous, error: $exception->getMessage());
        }

        $desired = $op->desiredPayload ?? [];
        $normalized = $found === null ? null : PayloadNormalizer::normalize($op->type, $found, $agentIds);

        if ($found !== null && DefinitionDiff::between($desired, $normalized ?? [])->isEmpty()) {
            $this->saveApplied($connection, $op, $found, $desired, $agentIds);

            return $this->succeeded($op, $found);
        }

        $this->markAmbiguous($connection, $op, $found);

        return new SyncOperationResult($op->id, SyncOperationOutcome::Ambiguous, error: $exception->getMessage());
    }

    /**
     * Post-ambiguity reconciliation after a TransportException on a
     * delete: a remote read that no longer finds the resource proves the
     * delete landed; a still-present resource is ambiguous and blocks.
     */
    private function reconcileAmbiguousDelete(string $connection, SyncOperation $op, ManagedResourceState $state, TransportException $exception): SyncOperationResult
    {
        $nameOrId = $op->type === ResourceType::Tool
            ? ($state->remoteName ?? $state->localKey)
            : ($state->remoteId ?? '');

        try {
            $found = $this->writer->readRemote($op->type->value, $nameOrId);
        } catch (\Throwable) {
            $this->markAmbiguous($connection, $op, null);

            return new SyncOperationResult($op->id, SyncOperationOutcome::Ambiguous, error: $exception->getMessage());
        }

        if ($found === null) {
            $this->store->deleteResource($connection, $op->type, $op->localKey);

            return new SyncOperationResult($op->id, SyncOperationOutcome::Succeeded);
        }

        $this->markAmbiguous($connection, $op, $found);

        return new SyncOperationResult($op->id, SyncOperationOutcome::Ambiguous, error: $exception->getMessage());
    }

    /**
     * Persists the applied state after a proven mutation: remote id, name,
     * marker, desired hash over the desired payload, the desired payload
     * as last applied, applied status and the synchronization timestamp.
     *
     * @param  array<string, mixed>  $result  Remote payload as observed after the mutation.
     * @param  array<string, mixed>  $desired
     * @param  array<string, string>  $agentIds
     */
    private function saveApplied(string $connection, SyncOperation $op, array $result, array $desired, array &$agentIds): void
    {
        $remoteId = $this->stringValue($result, 'id') ?? $op->remoteId;
        $remoteName = $this->stringValue($result, 'name') ?? $op->remoteName ?? $op->localKey;

        $this->store->saveResource(new ManagedResourceState(
            connection: $connection,
            type: $op->type,
            localKey: $op->localKey,
            remoteId: $remoteId,
            remoteName: $remoteName,
            desiredHash: $this->hasher->resourceHash($op->type->value, $op->localKey, $desired),
            lastAppliedPayload: $desired,
            remoteMarker: $result['updated_at'] ?? $op->remoteMarker,
            synchronizedAt: new \DateTimeImmutable,
            status: ManagedResourceStatus::Applied,
            metadata: [],
        ));

        if ($op->type === ResourceType::Agent && $remoteId !== null) {
            $agentIds[$op->localKey] = $remoteId;
        }
    }

    /**
     * Records a resource as ambiguous when the outcome of a mutation
     * could not be proven, preserving whatever was known before.
     *
     * @param  array<string, mixed>|null  $found  Remote payload observed during reconciliation, if any.
     */
    private function markAmbiguous(string $connection, SyncOperation $op, ?array $found): void
    {
        $existing = $this->store->getResource($connection, $op->type, $op->localKey);

        $remoteId = $this->stringValue($found ?? [], 'id') ?? $op->remoteId;
        $remoteName = $this->stringValue($found ?? [], 'name') ?? $op->remoteName ?? $op->localKey;
        $remoteMarker = $this->stringValue($found ?? [], 'updated_at') ?? $op->remoteMarker;

        if ($existing !== null) {
            $remoteId ??= $existing->remoteId;
            $remoteMarker ??= $existing->remoteMarker;
        }

        $this->store->saveResource(new ManagedResourceState(
            connection: $connection,
            type: $op->type,
            localKey: $op->localKey,
            remoteId: $remoteId,
            remoteName: $remoteName,
            desiredHash: $existing?->desiredHash,
            lastAppliedPayload: $existing?->lastAppliedPayload,
            remoteMarker: $remoteMarker,
            synchronizedAt: $existing?->synchronizedAt,
            status: ManagedResourceStatus::Ambiguous,
            metadata: $existing->metadata ?? [],
        ));
    }

    /**
     * Rewrites the local agent keys of a team payload to remote agent
     * definition ids using the live map, which is seeded from the state
     * store and updated as agent operations succeed during this apply.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $agentIds
     * @return array<string, mixed>
     */
    private function rewriteTeamPayload(array $payload, array $agentIds): array
    {
        $rewritten = $payload;

        if (isset($rewritten['orchestrator_id']) && is_string($rewritten['orchestrator_id'])) {
            $orchestratorId = $agentIds[$rewritten['orchestrator_id']] ?? null;

            if ($orchestratorId === null) {
                throw new \RuntimeException(sprintf(
                    'Team payload references agent "%s" which has no remote id yet; synchronize agents before teams.',
                    $rewritten['orchestrator_id'],
                ));
            }

            $rewritten['orchestrator_id'] = $orchestratorId;
        }

        if (isset($rewritten['members']) && is_array($rewritten['members'])) {
            $members = [];

            foreach ($rewritten['members'] as $member) {
                if (is_array($member) && isset($member['agent_definition_id']) && is_string($member['agent_definition_id'])) {
                    $memberId = $agentIds[$member['agent_definition_id']] ?? null;

                    if ($memberId === null) {
                        throw new \RuntimeException(sprintf(
                            'Team payload references agent "%s" which has no remote id yet; synchronize agents before teams.',
                            $member['agent_definition_id'],
                        ));
                    }

                    $member['agent_definition_id'] = $memberId;
                }

                $members[] = $member;
            }

            $rewritten['members'] = $members;
        }

        return $rewritten;
    }

    private function remoteIdOrFail(SyncOperation $op, ManagedResourceState $state): string
    {
        if ($state->remoteId === null) {
            throw new \RuntimeException(sprintf(
                'Cannot delete "%s": the state record has no remote id.',
                $op->id,
            ));
        }

        return $state->remoteId;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function succeeded(SyncOperation $op, array $result): SyncOperationResult
    {
        return new SyncOperationResult(
            $op->id,
            SyncOperationOutcome::Succeeded,
            remoteId: $this->stringValue($result, 'id') ?? $op->remoteId,
            remoteName: $this->stringValue($result, 'name') ?? $op->remoteName ?? $op->localKey,
            remoteMarker: $this->stringValue($result, 'updated_at') ?? $op->remoteMarker,
        );
    }

    private function failed(SyncOperation $op, ApiException $exception): SyncOperationResult
    {
        return new SyncOperationResult(
            $op->id,
            SyncOperationOutcome::Failed,
            error: $exception->getMessage(),
            apiError: $exception->apiError,
        );
    }

    /**
     * Extracts a string field from a normalized payload, or null when
     * absent or not a string.
     *
     * @param  array<string, mixed>  $payload
     */
    private function stringValue(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    private function resultFor(SyncOperation $op, SyncOperationOutcome $outcome): SyncOperationResult
    {
        return new SyncOperationResult($op->id, $outcome);
    }

    /**
     * Sorts executable operations into the binding execution order:
     * upserts tools -> agents -> teams, then prune deletions
     * teams -> agents -> tools. PHP 8 stable sort preserves the plan
     * order inside each type group.
     *
     * @return list<SyncOperation>
     */
    private function orderOperations(SyncPlan $plan): array
    {
        $upserts = [];
        $deletes = [];

        foreach ($plan->executable() as $op) {
            if ($op->action === SyncAction::Delete) {
                $deletes[] = $op;
            } else {
                $upserts[] = $op;
            }
        }

        usort($upserts, static fn (SyncOperation $left, SyncOperation $right): int => $left->type->upsertRank() <=> $right->type->upsertRank());
        usort($deletes, static fn (SyncOperation $left, SyncOperation $right): int => $left->type->deleteRank() <=> $right->type->deleteRank());

        return [...$upserts, ...$deletes];
    }

    /**
     * @param  list<SyncOperationResult>  $results
     */
    private function computeStatus(SyncPlan $plan, array $results, bool $unexpected): SyncReportStatus
    {
        if ($unexpected) {
            return SyncReportStatus::Partial;
        }

        foreach ($results as $result) {
            if ($result->outcome === SyncOperationOutcome::Failed) {
                return SyncReportStatus::Failed;
            }

            if ($result->outcome === SyncOperationOutcome::Ambiguous) {
                return SyncReportStatus::Blocked;
            }
        }

        if ($plan->blocking() !== []) {
            return SyncReportStatus::Blocked;
        }

        return SyncReportStatus::Succeeded;
    }

    /**
     * @param  list<SyncOperationResult>  $results
     * @return array<string, int>
     */
    private function summarize(array $results): array
    {
        $summary = [
            'total' => count($results),
            'pending' => 0,
            'skipped' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'ambiguous' => 0,
        ];

        foreach ($results as $result) {
            $summary[$result->outcome->value]++;
        }

        return $summary;
    }

    /**
     * @param  list<SyncOperationResult>  $results
     */
    private function buildReport(SyncPlan $plan, \DateTimeImmutable $startedAt, SyncReportStatus $status, array $results, bool $manifestReady): SyncReport
    {
        return new SyncReport(
            connection: $plan->connection,
            manifestHash: $plan->manifestHash,
            planHash: $plan->planHash,
            startedAt: $startedAt,
            finishedAt: new \DateTimeImmutable,
            status: $status,
            results: $results,
            manifestReady: $manifestReady,
            summary: $this->summarize($results),
        );
    }
}
