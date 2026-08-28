<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Planner;

use Digitalygo\Envai\Definitions\Agent\AgentDefinition;
use Digitalygo\Envai\Definitions\Compiler\DefinitionCompilationResult;
use Digitalygo\Envai\Definitions\Team\TeamDefinition;
use Digitalygo\Envai\Definitions\Tool\ToolDefinition;
use Digitalygo\Envai\Exceptions\DefinitionDriftException;
use Digitalygo\Envai\Models\Json\CanonicalJsonSerializer;
use Digitalygo\Envai\Sync\Diff\DefinitionDiff;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Sync\State\SyncStateStore;

/**
 * Builds a SyncPlan from the compiled definitions, the state store and
 * the remote resources, applying the binding three-way classification
 * table (project document section 19.2) and the explicit-only policy
 * overrides (section 19.3).
 *
 * The planner is read-only: it never acquires the lock and never mutates
 * anything. Remote teams are compared through agent ids rewritten to
 * local keys, so a synced team whose members keep their remote ids is
 * classified unchanged instead of drifted.
 *
 * With failOnDrift the plan is still fully built (dry-run friendly) and
 * then DefinitionDriftException is thrown listing the drifted operation
 * ids when any drift is present.
 */
final class SyncPlanner
{
    public function __construct(
        private readonly RemoteReader $remote,
        private readonly SyncStateStore $store,
    ) {}

    public function plan(DefinitionCompilationResult $compiled, SyncOptions $options, string $connection): SyncPlan
    {
        $manifest = $compiled->manifest;

        $toolsByName = $this->indexBy($this->remote->listTools(), 'name');
        $agentsById = $this->indexBy($this->remote->listAgents(), 'id');
        $agentsByName = $this->indexBy($this->remote->listAgents(), 'name');
        $teamsById = $this->indexBy($this->remote->listTeams(), 'id');
        $teamsByName = $this->indexBy($this->remote->listTeams(), 'name');

        $agentIdToLocalKey = $this->agentIdToLocalKey($connection);

        $operations = [];

        foreach ($manifest->tools as $localKey => $tool) {
            $desired = PayloadNormalizer::normalize(ResourceType::Tool, $tool->toRemotePayload());
            $state = $this->store->getResource($connection, ResourceType::Tool, $localKey);
            $rawRemote = $toolsByName[$localKey] ?? null;
            $remote = $rawRemote === null ? null : PayloadNormalizer::normalize(ResourceType::Tool, $rawRemote);

            $operations[] = $this->classify(ResourceType::Tool, $localKey, $desired, $state, $remote, $rawRemote, $options, $agentIdToLocalKey);
        }

        foreach ($manifest->agents as $localKey => $agent) {
            $desired = PayloadNormalizer::normalize(ResourceType::Agent, $agent->toRemotePayload());
            $state = $this->store->getResource($connection, ResourceType::Agent, $localKey);
            $rawRemote = $this->matchAgentOrTeamRemote($state, $agentsById, $agentsByName, $agent->name);
            $remote = $rawRemote === null ? null : PayloadNormalizer::normalize(ResourceType::Agent, $rawRemote, $agentIdToLocalKey);

            $operations[] = $this->classify(ResourceType::Agent, $localKey, $desired, $state, $remote, $rawRemote, $options, $agentIdToLocalKey);
        }

        foreach ($manifest->teams as $localKey => $team) {
            $identity = [$team->orchestratorKey => $team->orchestratorKey];

            foreach ($team->members as $member) {
                $identity[$member->localAgentKey] = $member->localAgentKey;
            }

            $desired = PayloadNormalizer::normalize(ResourceType::Team, $team->toRemotePayload($identity, []), $agentIdToLocalKey);
            $state = $this->store->getResource($connection, ResourceType::Team, $localKey);
            $rawRemote = $this->matchAgentOrTeamRemote($state, $teamsById, $teamsByName, $team->name);
            $remote = $rawRemote === null ? null : PayloadNormalizer::normalize(ResourceType::Team, $rawRemote, $agentIdToLocalKey);

            $operations[] = $this->classify(ResourceType::Team, $localKey, $desired, $state, $remote, $rawRemote, $options, $agentIdToLocalKey);
        }

        $operations = [...$operations, ...$this->orphanOperations($connection, $manifest->tools, $manifest->agents, $manifest->teams, $options, $agentIdToLocalKey)];

        $operations = $this->applyScopeFilter($operations, $options);

        $planHash = $this->hashPlan($connection, $manifest->hash, $options, $operations);

        $plan = new SyncPlan(
            connection: $connection,
            manifestHash: $manifest->hash,
            planHash: $planHash,
            plannedAt: new \DateTimeImmutable,
            options: $options,
            operations: $operations,
        );

        if ($options->failOnDrift) {
            $drifted = array_values(array_filter(
                $operations,
                static fn (SyncOperation $op): bool => $op->classification === SyncClassification::Drift,
            ));

            if ($drifted !== []) {
                $ids = array_map(static fn (SyncOperation $op): string => $op->id, $drifted);

                throw new DefinitionDriftException(sprintf(
                    'Drift detected for %s; the remote changed after the last apply. Rerun with --overwrite-drift to overwrite the remote state.',
                    implode(', ', $ids),
                ));
            }
        }

        return $plan;
    }

    /**
     * Classifies one manifest-declared resource per the section 19.2
     * table and applies the explicit-only policies of section 19.3.
     *
     * @param  array<string, mixed>  $desired  Normalized desired payload.
     * @param  array<string, mixed>|null  $remote  Normalized matched remote payload, or null.
     * @param  array<string, mixed>|null  $rawRemote  Un-normalized matched remote payload (id/name/marker source), or null.
     * @param  array<string, string>  $agentIdToLocalKey
     */
    private function classify(
        ResourceType $type,
        string $localKey,
        array $desired,
        ?ManagedResourceState $state,
        ?array $remote,
        ?array $rawRemote,
        SyncOptions $options,
        array $agentIdToLocalKey,
    ): SyncOperation {
        $applied = $state !== null
            ? PayloadNormalizer::normalize($type, $state->lastAppliedPayload, $agentIdToLocalKey)
            : [];

        $remoteId = $state?->remoteId;
        $remoteName = $state?->remoteName;
        $stateMarker = $state?->remoteMarker;

        $remoteId = is_string($remoteId) ? $remoteId : $this->stringValue($rawRemote, 'id');
        $remoteName = is_string($remoteName) ? $remoteName : $this->stringValue($rawRemote, 'name');
        $remoteMarker = is_string($stateMarker) ? $stateMarker : $this->stringValue($rawRemote, 'updated_at');

        if ($state === null) {
            if ($remote === null) {
                return $this->operation(
                    $type,
                    $localKey,
                    SyncClassification::Create,
                    SyncAction::Create,
                    blocking: false,
                    reasonCode: null,
                    remoteId: null,
                    remoteName: null,
                    remoteMarker: null,
                    desired: $desired,
                    applied: null,
                    remote: null,
                    diff: DefinitionDiff::between($desired, []),
                );
            }

            $adopt = $options->adopt;

            return $this->operation(
                $type,
                $localKey,
                SyncClassification::AdoptionRequired,
                $adopt ? SyncAction::Adopt : SyncAction::None,
                blocking: ! $adopt,
                reasonCode: $adopt ? null : 'adoption_required',
                remoteId: $this->stringValue($rawRemote, 'id'),
                remoteName: $this->stringValue($rawRemote, 'name'),
                remoteMarker: $this->stringValue($rawRemote, 'updated_at'),
                desired: $desired,
                applied: null,
                remote: $remote,
                diff: DefinitionDiff::between($desired, $remote),
            );
        }

        if ($remote === null) {
            $recreate = $options->recreateMissing;

            return $this->operation(
                $type,
                $localKey,
                SyncClassification::MissingRemote,
                $recreate ? SyncAction::Recreate : SyncAction::None,
                blocking: ! $recreate,
                reasonCode: $recreate ? null : 'missing_remote',
                remoteId: $remoteId,
                remoteName: $remoteName,
                remoteMarker: $remoteMarker,
                desired: $desired,
                applied: $applied,
                remote: null,
                diff: DefinitionDiff::between($desired, $applied),
            );
        }

        $desiredEqualsApplied = DefinitionDiff::between($desired, $applied)->isEmpty();
        $remoteEqualsApplied = DefinitionDiff::between($remote, $applied)->isEmpty();

        if ($desiredEqualsApplied && $remoteEqualsApplied) {
            return $this->operation(
                $type,
                $localKey,
                SyncClassification::Unchanged,
                SyncAction::None,
                blocking: false,
                reasonCode: null,
                remoteId: $remoteId,
                remoteName: $remoteName,
                remoteMarker: $remoteMarker,
                desired: $desired,
                applied: $applied,
                remote: $remote,
                diff: DefinitionDiff::between($desired, $applied),
            );
        }

        // Desired changed, remote still matches the last apply: plain update.
        if ($remoteEqualsApplied) {
            return $this->operation(
                $type,
                $localKey,
                SyncClassification::Update,
                SyncAction::Update,
                blocking: false,
                reasonCode: null,
                remoteId: $remoteId,
                remoteName: $remoteName,
                remoteMarker: $remoteMarker,
                desired: $desired,
                applied: $applied,
                remote: $remote,
                diff: DefinitionDiff::between($desired, $applied),
            );
        }

        // Remote changed after the last apply: drift, or an authorized overwrite.
        if ($desiredEqualsApplied) {
            $overwrite = $options->overwriteDrift;

            return $this->operation(
                $type,
                $localKey,
                SyncClassification::Drift,
                $overwrite ? SyncAction::Update : SyncAction::None,
                blocking: ! $overwrite,
                reasonCode: $overwrite ? null : 'drift',
                remoteId: $remoteId,
                remoteName: $remoteName,
                remoteMarker: $remoteMarker,
                desired: $desired,
                applied: $applied,
                remote: $remote,
                diff: DefinitionDiff::between($remote, $applied),
            );
        }

        // Both desired and remote moved away from the last apply: conflict,
        // never overridable.
        return $this->operation(
            $type,
            $localKey,
            SyncClassification::Conflict,
            SyncAction::None,
            blocking: true,
            reasonCode: 'conflict',
            remoteId: $remoteId,
            remoteName: $remoteName,
            remoteMarker: $remoteMarker,
            desired: $desired,
            applied: $applied,
            remote: $remote,
            diff: DefinitionDiff::between($desired, $applied),
        );
    }

    /**
     * Builds the orphan_managed operations for store resources no longer
     * declared by the manifest. Orphans are deterministic-ordered by
     * type then local key and only delete with the explicit prune option.
     *
     * @param  array<string, ToolDefinition>  $tools
     * @param  array<string, AgentDefinition>  $agents
     * @param  array<string, TeamDefinition>  $teams
     * @param  array<string, string>  $agentIdToLocalKey
     * @return list<SyncOperation>
     */
    private function orphanOperations(
        string $connection,
        array $tools,
        array $agents,
        array $teams,
        SyncOptions $options,
        array $agentIdToLocalKey,
    ): array {
        $declared = [
            ResourceType::Tool->value => array_keys($tools),
            ResourceType::Agent->value => array_keys($agents),
            ResourceType::Team->value => array_keys($teams),
        ];

        $toolsByName = $this->indexBy($this->remote->listTools(), 'name');
        $agentsById = $this->indexBy($this->remote->listAgents(), 'id');
        $agentsByName = $this->indexBy($this->remote->listAgents(), 'name');
        $teamsById = $this->indexBy($this->remote->listTeams(), 'id');
        $teamsByName = $this->indexBy($this->remote->listTeams(), 'name');

        $orphans = [];

        foreach ($this->store->resources($connection) as $state) {
            if (in_array($state->localKey, $declared[$state->type->value], true)) {
                continue;
            }

            $orphans[] = $state;
        }

        usort($orphans, static function (ManagedResourceState $left, ManagedResourceState $right): int {
            $byType = $left->type->deleteRank() <=> $right->type->deleteRank();

            return $byType !== 0 ? $byType : strcmp($left->localKey, $right->localKey);
        });

        $operations = [];

        foreach ($orphans as $state) {
            $remote = $this->matchOrphanRemote($state, $toolsByName, $agentsById, $agentsByName, $teamsById, $teamsByName);

            $prune = $options->prune;

            $applied = $state->lastAppliedPayload;
            $normalizedApplied = PayloadNormalizer::normalize($state->type, $applied, $agentIdToLocalKey);
            $marker = $state->remoteMarker;

            $operations[] = $this->operation(
                $state->type,
                $state->localKey,
                SyncClassification::OrphanManaged,
                $prune ? SyncAction::Delete : SyncAction::None,
                blocking: false,
                reasonCode: $prune ? null : 'prune_disabled',
                remoteId: $state->remoteId,
                remoteName: $state->remoteName,
                remoteMarker: is_string($marker) ? $marker : null,
                desired: null,
                applied: $normalizedApplied,
                remote: $remote,
                diff: DefinitionDiff::between([], $remote ?? $normalizedApplied),
            );
        }

        return $operations;
    }

    /**
     * Matches a remote payload for an orphaned store resource: tools by
     * name, agents and teams by the stored remote id first, then by the
     * stored remote name.
     *
     * @param  array<string, array<string, mixed>>  $toolsByName
     * @param  array<string, array<string, mixed>>  $agentsById
     * @param  array<string, array<string, mixed>>  $agentsByName
     * @param  array<string, array<string, mixed>>  $teamsById
     * @param  array<string, array<string, mixed>>  $teamsByName
     * @return array<string, mixed>|null
     */
    private function matchOrphanRemote(
        ManagedResourceState $state,
        array $toolsByName,
        array $agentsById,
        array $agentsByName,
        array $teamsById,
        array $teamsByName,
    ): ?array {
        if ($state->type === ResourceType::Tool) {
            $payload = $toolsByName[$state->localKey] ?? null;

            return $payload === null ? null : PayloadNormalizer::normalize(ResourceType::Tool, $payload);
        }

        $byId = $state->type === ResourceType::Agent ? $agentsById : $teamsById;
        $byName = $state->type === ResourceType::Agent ? $agentsByName : $teamsByName;

        $payload = null;

        if ($state->remoteId !== null) {
            $payload = $byId[$state->remoteId] ?? null;
        }

        if ($payload === null && $state->remoteName !== null) {
            $payload = $byName[$state->remoteName] ?? null;
        }

        return $payload === null ? null : PayloadNormalizer::normalize($state->type, $payload, []);
    }

    /**
     * Matches the remote payload of an agent or team definition: the
     * stored remote id first (the resource is linked), then the remote
     * name for bootstrap when nothing was ever stored.
     *
     * @param  array<string, array<string, mixed>>  $byId
     * @param  array<string, array<string, mixed>>  $byName
     * @return array<string, mixed>|null
     */
    private function matchAgentOrTeamRemote(?ManagedResourceState $state, array $byId, array $byName, string $desiredName): ?array
    {
        $payload = null;

        if ($state !== null && $state->remoteId !== null) {
            $payload = $byId[$state->remoteId] ?? null;
        }

        if ($payload === null) {
            $payload = $byName[$desiredName] ?? null;
        }

        return $payload === null ? null : $payload;
    }

    /**
     * Marks every operation whose type is outside the requested scope as
     * skipped with reason `out_of_scope`. The classification and payloads
     * are preserved for reporting.
     *
     * @param  list<SyncOperation>  $operations
     * @return list<SyncOperation>
     */
    private function applyScopeFilter(array $operations, SyncOptions $options): array
    {
        if ($options->scope === SyncScope::All) {
            return $operations;
        }

        $allowed = match ($options->scope) {
            SyncScope::Tools => ResourceType::Tool,
            SyncScope::Agents => ResourceType::Agent,
            SyncScope::Teams => ResourceType::Team,
        };

        return array_map(static function (SyncOperation $op) use ($allowed): SyncOperation {
            if ($op->type === $allowed) {
                return $op;
            }

            return new SyncOperation(
                id: $op->id,
                type: $op->type,
                localKey: $op->localKey,
                classification: $op->classification,
                action: SyncAction::None,
                blocking: false,
                reasonCode: 'out_of_scope',
                remoteId: $op->remoteId,
                remoteName: $op->remoteName,
                remoteMarker: $op->remoteMarker,
                desiredPayload: $op->desiredPayload,
                lastAppliedPayload: $op->lastAppliedPayload,
                remotePayload: $op->remotePayload,
                diff: $op->diff,
                destructive: $op->destructive,
            );
        }, $operations);
    }

    /**
     * @param  array<string, mixed>|null  $desired
     * @param  array<string, mixed>|null  $applied
     * @param  array<string, mixed>|null  $remote
     */
    private function operation(
        ResourceType $type,
        string $localKey,
        SyncClassification $classification,
        SyncAction $action,
        bool $blocking,
        ?string $reasonCode,
        ?string $remoteId,
        ?string $remoteName,
        ?string $remoteMarker,
        ?array $desired,
        ?array $applied,
        ?array $remote,
        DefinitionDiff $diff,
    ): SyncOperation {
        return new SyncOperation(
            id: $type->value.':'.$localKey,
            type: $type,
            localKey: $localKey,
            classification: $classification,
            action: $action,
            blocking: $blocking,
            reasonCode: $reasonCode,
            remoteId: $remoteId,
            remoteName: $remoteName,
            remoteMarker: $remoteMarker,
            desiredPayload: $desired,
            lastAppliedPayload: $applied,
            remotePayload: $remote,
            diff: $diff,
            destructive: $action === SyncAction::Delete,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $payloads
     * @return array<string, array<string, mixed>>
     */
    private function indexBy(array $payloads, string $key): array
    {
        $indexed = [];

        foreach ($payloads as $payload) {
            if (isset($payload[$key]) && is_string($payload[$key])) {
                $indexed[$payload[$key]] = $payload;
            }
        }

        return $indexed;
    }

    /**
     * Extracts a string field from a normalized payload, or null when
     * absent or not a string.
     *
     * @param  array<string, mixed>|null  $payload
     */
    private function stringValue(?array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @return array<string, string> Remote agent id to local agent key, for managed agents only.
     */
    private function agentIdToLocalKey(string $connection): array
    {
        $map = [];

        foreach ($this->store->resources($connection) as $state) {
            if ($state->type === ResourceType::Agent && $state->remoteId !== null) {
                $map[$state->remoteId] = $state->localKey;
            }
        }

        return $map;
    }

    /**
     * Deterministic plan hash: sha256 over the canonical JSON serialization
     * of the ordered operations plus the connection, manifest hash, scope
     * and policy overrides.
     *
     * @param  list<SyncOperation>  $operations
     */
    private function hashPlan(string $connection, string $manifestHash, SyncOptions $options, array $operations): string
    {
        $serialized = array_map(static fn (SyncOperation $op): array => [
            'id' => $op->id,
            'type' => $op->type->value,
            'local_key' => $op->localKey,
            'classification' => $op->classification->value,
            'action' => $op->action->value,
            'blocking' => $op->blocking,
            'reason_code' => $op->reasonCode,
            'remote_id' => $op->remoteId,
            'remote_name' => $op->remoteName,
            'remote_marker' => $op->remoteMarker,
            'desired_payload' => $op->desiredPayload,
            'last_applied_payload' => $op->lastAppliedPayload,
            'remote_payload' => $op->remotePayload,
            'destructive' => $op->destructive,
        ], $operations);

        $document = [
            'format_version' => 1,
            'connection' => $connection,
            'manifest_hash' => $manifestHash,
            'scope' => $options->scope->value,
            'adopt' => $options->adopt,
            'overwrite_drift' => $options->overwriteDrift,
            'recreate_missing' => $options->recreateMissing,
            'prune' => $options->prune,
            'operations' => $serialized,
        ];

        $canonical = CanonicalJsonSerializer::serialize($document);

        return 'sha256:'.hash('sha256', $canonical);
    }
}
