<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\Planner;

use Digitalygo\Envai\Definitions\Compiler\DefinitionCompilationResult;
use Digitalygo\Envai\Definitions\Tool\ToolDefinition;
use Digitalygo\Envai\Exceptions\DefinitionDriftException;
use Digitalygo\Envai\Sync\Planner\SyncAction;
use Digitalygo\Envai\Sync\Planner\SyncClassification;
use Digitalygo\Envai\Sync\Planner\SyncOperation;
use Digitalygo\Envai\Sync\Planner\SyncOptions;
use Digitalygo\Envai\Sync\Planner\SyncPlan;
use Digitalygo\Envai\Sync\Planner\SyncPlanner;
use Digitalygo\Envai\Sync\Planner\SyncScope;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Sync\State\SyncStateStore;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteReader;
use Digitalygo\Envai\Tests\Sync\SyncTestCase;

final class SyncPlannerTest extends SyncTestCase
{
    private const C = 'production';

    public function test_empty_everything_produces_an_empty_plan(): void
    {
        $plan = $this->plan($this->compiled());

        $this->assertSame([], $plan->operations);
        $this->assertFalse($plan->isPartialScope());
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $plan->planHash);
    }

    public function test_fresh_declarations_are_classified_create_in_tool_agent_team_order(): void
    {
        $compiled = $this->compiled(
            tools: ['query_db' => $this->tool('query_db')],
            agents: ['researcher' => $this->agent('researcher')],
            teams: ['forecasting' => $this->team('forecasting', 'researcher')],
        );

        $plan = $this->plan($compiled);

        $this->assertSame([
            'tool:query_db',
            'agent:researcher',
            'team:forecasting',
        ], array_map(static fn (SyncOperation $op): string => $op->id, $plan->operations));

        $this->assertSame(SyncClassification::Create, $plan->operations[0]->classification);
        $this->assertSame(SyncAction::Create, $plan->operations[0]->action);
        $this->assertFalse($plan->operations[0]->blocking);
        $this->assertSame(['name' => 'query_db', 'kind' => 'client', 'description' => 'Tool query_db', 'parameters' => ['type' => 'object', 'properties' => []], 'timeout_seconds' => 30], $plan->operations[0]->desiredPayload);
    }

    public function test_unchanged_resources_produce_no_executable_operations(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);

        $store = $this->storeWithAppliedTool('query_db', $compiled);
        $reader = new FakeRemoteReader(tools: [$this->remoteTool('query_db')]);

        $plan = $this->plan($compiled, $reader, $store);

        $this->assertSame(SyncClassification::Unchanged, $plan->operations[0]->classification);
        $this->assertSame(SyncAction::None, $plan->operations[0]->action);
        $this->assertSame([], $plan->executable());
    }

    public function test_changed_desired_with_remote_matching_apply_is_update(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db', timeoutSeconds: 60)]);

        $store = $this->storeWithAppliedTool('query_db', $this->compiled(tools: ['query_db' => $this->tool('query_db')]));
        $reader = new FakeRemoteReader(tools: [$this->remoteTool('query_db')]);

        $plan = $this->plan($compiled, $reader, $store);

        $this->assertSame(SyncClassification::Update, $plan->operations[0]->classification);
        $this->assertSame(SyncAction::Update, $plan->operations[0]->action);
        $this->assertFalse($plan->operations[0]->blocking);
        $this->assertSame([$plan->operations[0]], $plan->executable());
    }

    public function test_drift_blocks_by_default(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);

        $store = $this->storeWithAppliedTool('query_db', $compiled);
        $reader = new FakeRemoteReader(tools: [$this->remoteTool('query_db', ['description' => 'Changed remotely'])]);

        $plan = $this->plan($compiled, $reader, $store);

        $this->assertSame(SyncClassification::Drift, $plan->operations[0]->classification);
        $this->assertSame(SyncAction::None, $plan->operations[0]->action);
        $this->assertTrue($plan->operations[0]->blocking);
        $this->assertSame('drift', $plan->operations[0]->reasonCode);
        $this->assertSame([$plan->operations[0]], $plan->blocking());
    }

    public function test_drift_with_overwrite_drift_becomes_update(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);

        $store = $this->storeWithAppliedTool('query_db', $compiled);
        $reader = new FakeRemoteReader(tools: [$this->remoteTool('query_db', ['description' => 'Changed remotely'])]);

        $plan = $this->plan($compiled, $reader, $store, new SyncOptions(overwriteDrift: true));

        $this->assertSame(SyncClassification::Drift, $plan->operations[0]->classification);
        $this->assertSame(SyncAction::Update, $plan->operations[0]->action);
        $this->assertFalse($plan->operations[0]->blocking);
    }

    public function test_conflict_blocks_even_with_all_overrides(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db', timeoutSeconds: 60)]);

        $store = $this->storeWithAppliedTool('query_db', $this->compiled(tools: ['query_db' => $this->tool('query_db')]));
        $reader = new FakeRemoteReader(tools: [$this->remoteTool('query_db', ['description' => 'Remote changed', 'timeout_seconds' => 45])]);

        $options = new SyncOptions(adopt: true, overwriteDrift: true, recreateMissing: true, prune: true);

        $plan = $this->plan($compiled, $reader, $store, $options);

        $this->assertSame(SyncClassification::Conflict, $plan->operations[0]->classification);
        $this->assertSame(SyncAction::None, $plan->operations[0]->action);
        $this->assertTrue($plan->operations[0]->blocking);
        $this->assertSame('conflict', $plan->operations[0]->reasonCode);
    }

    public function test_adoption_required_blocks_by_default_and_shows_diff(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);

        $reader = new FakeRemoteReader(tools: [$this->remoteTool('query_db', ['description' => 'Existing remotely'])]);

        $plan = $this->plan($compiled, $reader, new InMemorySyncStateStore);

        $this->assertSame(SyncClassification::AdoptionRequired, $plan->operations[0]->classification);
        $this->assertSame(SyncAction::None, $plan->operations[0]->action);
        $this->assertTrue($plan->operations[0]->blocking);
        $this->assertSame('adoption_required', $plan->operations[0]->reasonCode);
        $this->assertFalse($plan->operations[0]->diff?->isEmpty());
        $this->assertSame('twl_query_db', $plan->operations[0]->remoteId);
    }

    public function test_adoption_is_authorized_with_adopt(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);

        $reader = new FakeRemoteReader(tools: [$this->remoteTool('query_db', ['description' => 'Existing remotely'])]);

        $plan = $this->plan($compiled, $reader, new InMemorySyncStateStore, new SyncOptions(adopt: true));

        $this->assertSame(SyncClassification::AdoptionRequired, $plan->operations[0]->classification);
        $this->assertSame(SyncAction::Adopt, $plan->operations[0]->action);
        $this->assertFalse($plan->operations[0]->blocking);
        $this->assertSame('Existing remotely', $plan->operations[0]->remotePayload['description']);
    }

    public function test_missing_remote_blocks_by_default(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);

        $store = $this->storeWithAppliedTool('query_db', $compiled);

        $plan = $this->plan($compiled, new FakeRemoteReader, $store);

        $this->assertSame(SyncClassification::MissingRemote, $plan->operations[0]->classification);
        $this->assertSame(SyncAction::None, $plan->operations[0]->action);
        $this->assertTrue($plan->operations[0]->blocking);
        $this->assertSame('missing_remote', $plan->operations[0]->reasonCode);
        $this->assertSame('twl_query_db', $plan->operations[0]->remoteId);
    }

    public function test_missing_remote_becomes_recreate_with_option(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);

        $store = $this->storeWithAppliedTool('query_db', $compiled);

        $plan = $this->plan($compiled, new FakeRemoteReader, $store, new SyncOptions(recreateMissing: true));

        $this->assertSame(SyncClassification::MissingRemote, $plan->operations[0]->classification);
        $this->assertSame(SyncAction::Recreate, $plan->operations[0]->action);
        $this->assertFalse($plan->operations[0]->blocking);
    }

    public function test_orphan_managed_is_skipped_without_prune(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);

        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Tool, 'old_tool', remoteId: 'twl_old', remoteName: 'old_tool', lastAppliedPayload: ['name' => 'old_tool'], status: ManagedResourceStatus::Applied));

        $plan = $this->plan($compiled, new FakeRemoteReader(tools: [$this->remoteTool('old_tool')]), $store);

        $orphan = $this->operation($plan, 'tool:old_tool');

        $this->assertSame(SyncClassification::OrphanManaged, $orphan->classification);
        $this->assertSame(SyncAction::None, $orphan->action);
        $this->assertFalse($orphan->blocking);
        $this->assertSame('prune_disabled', $orphan->reasonCode);
        $this->assertFalse($orphan->destructive);
    }

    public function test_orphan_managed_is_deleted_with_prune(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);

        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Tool, 'old_tool', remoteId: 'twl_old', remoteName: 'old_tool', status: ManagedResourceStatus::Applied));

        $plan = $this->plan($compiled, new FakeRemoteReader(tools: [$this->remoteTool('old_tool')]), $store, new SyncOptions(prune: true));

        $orphan = $this->operation($plan, 'tool:old_tool');

        $this->assertSame(SyncAction::Delete, $orphan->action);
        $this->assertTrue($orphan->destructive);
        $this->assertSame('twl_old', $orphan->remoteId);
    }

    public function test_prune_orders_orphans_by_type_and_key(): void
    {
        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Tool, 'ztool'));
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Team, 'ateam'));
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Agent, 'bagent'));

        $plan = $this->plan($this->compiled(), new FakeRemoteReader, $store, new SyncOptions(prune: true));

        $this->assertSame([
            'team:ateam',
            'agent:bagent',
            'tool:ztool',
        ], array_map(static fn (SyncOperation $op): string => $op->id, $plan->operations));
    }

    public function test_scope_filter_marks_out_of_scope_operations(): void
    {
        $compiled = $this->compiled(
            tools: ['query_db' => $this->tool('query_db')],
            agents: ['researcher' => $this->agent('researcher')],
            teams: ['forecasting' => $this->team('forecasting', 'researcher')],
        );

        $plan = $this->plan($compiled, new FakeRemoteReader, new InMemorySyncStateStore, new SyncOptions(scope: SyncScope::Tools));

        $this->assertTrue($plan->isPartialScope());

        foreach ($plan->operations as $op) {
            if ($op->type === ResourceType::Tool) {
                $this->assertSame(SyncAction::Create, $op->action);
            } else {
                $this->assertSame(SyncAction::None, $op->action);
                $this->assertSame('out_of_scope', $op->reasonCode);
                $this->assertFalse($op->blocking);
            }
        }

        $this->assertCount(1, $plan->executable());
    }

    public function test_fail_on_drift_throws_listing_drifted_keys_after_building(): void
    {
        $compiled = $this->compiled(
            tools: ['query_db' => $this->tool('query_db')],
            agents: ['researcher' => $this->agent('researcher')],
        );

        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Tool, 'query_db', remoteId: 'twl_q', remoteName: 'query_db', lastAppliedPayload: $this->tool('query_db')->toRemotePayload(), status: ManagedResourceStatus::Applied));
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Agent, 'researcher', remoteId: 'agt_r', remoteName: 'researcher', lastAppliedPayload: $this->agent('researcher')->toRemotePayload(), status: ManagedResourceStatus::Applied));

        $reader = new FakeRemoteReader(
            tools: [$this->remoteTool('query_db', ['description' => 'Drifted'])],
            agents: [$this->remoteAgent('researcher', ['system_prompt' => 'Changed'])],
        );

        try {
            $this->plan($compiled, $reader, $store, new SyncOptions(failOnDrift: true));
            $this->fail('Expected DefinitionDriftException.');
        } catch (DefinitionDriftException $exception) {
            $this->assertStringContainsString('tool:query_db', $exception->getMessage());
            $this->assertStringContainsString('agent:researcher', $exception->getMessage());
        }
    }

    public function test_fail_on_drift_does_not_throw_without_drift(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $store = $this->storeWithAppliedTool('query_db', $compiled);

        $plan = $this->plan($compiled, new FakeRemoteReader(tools: [$this->remoteTool('query_db')]), $store, new SyncOptions(failOnDrift: true));

        $this->assertSame(SyncClassification::Unchanged, $plan->operations[0]->classification);
    }

    public function test_plan_hash_is_deterministic(): void
    {
        $compiled = $this->compiled(
            tools: ['query_db' => $this->tool('query_db')],
            agents: ['researcher' => $this->agent('researcher')],
        );

        $first = $this->plan($compiled);
        $second = $this->plan($compiled);

        $this->assertSame($first->planHash, $second->planHash);
        $this->assertEquals($first->operations, $second->operations);
    }

    public function test_plan_hash_changes_with_options(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);

        $a = $this->plan($compiled);
        $b = $this->plan($compiled, new FakeRemoteReader, new InMemorySyncStateStore, new SyncOptions(prune: true));

        $this->assertNotSame($a->planHash, $b->planHash);
    }

    public function test_plan_hash_changes_with_remote_drift(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $store = $this->storeWithAppliedTool('query_db', $compiled);

        $a = $this->plan($compiled, new FakeRemoteReader(tools: [$this->remoteTool('query_db')]), $store);
        $b = $this->plan($compiled, new FakeRemoteReader(tools: [$this->remoteTool('query_db', ['description' => 'Drifted'])]), $store);

        $this->assertNotSame($a->planHash, $b->planHash);
    }

    public function test_agent_is_matched_by_stored_remote_id_for_rename(): void
    {
        $compiled = $this->compiled(agents: ['orchestrator' => $this->agent('orchestrator', name: 'New name')]);

        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Agent, 'orchestrator', remoteId: 'agt_keep', remoteName: 'Old name', lastAppliedPayload: $this->agent('orchestrator', name: 'Old name')->toRemotePayload(), status: ManagedResourceStatus::Applied));

        $reader = new FakeRemoteReader(agents: [$this->remoteAgent('Old name', ['id' => 'agt_keep'])]);

        $plan = $this->plan($compiled, $reader, $store);

        $this->assertSame(SyncClassification::Update, $plan->operations[0]->classification);
        $this->assertSame('agt_keep', $plan->operations[0]->remoteId);
        $this->assertSame(SyncAction::Update, $plan->operations[0]->action);
    }

    public function test_agent_bootstrap_matches_by_name_when_not_linked(): void
    {
        $compiled = $this->compiled(agents: ['researcher' => $this->agent('researcher')]);

        $reader = new FakeRemoteReader(agents: [$this->remoteAgent('researcher')]);

        $plan = $this->plan($compiled, $reader, new InMemorySyncStateStore);

        $this->assertSame(SyncClassification::AdoptionRequired, $plan->operations[0]->classification);
        $this->assertSame('agt_researcher', $plan->operations[0]->remoteId);
    }

    public function test_agent_remote_id_priority_over_name_match(): void
    {
        $compiled = $this->compiled(agents: ['researcher' => $this->agent('researcher', name: 'Current')]);

        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Agent, 'researcher', remoteId: 'agt_current', remoteName: 'Current', lastAppliedPayload: $this->agent('researcher', name: 'Current')->toRemotePayload(), status: ManagedResourceStatus::Applied));

        // Another remote agent has the desired name but a different id; the
        // linked id must win.
        $reader = new FakeRemoteReader(agents: [
            $this->remoteAgent('Current', ['id' => 'agt_current']),
            $this->remoteAgent('Current', ['id' => 'agt_other']),
        ]);

        $plan = $this->plan($compiled, $reader, $store);

        $this->assertSame(SyncClassification::Unchanged, $plan->operations[0]->classification);
        $this->assertSame('agt_current', $plan->operations[0]->remoteId);
    }

    public function test_tool_rename_is_modeled_as_create_plus_orphan(): void
    {
        $compiled = $this->compiled(tools: ['new_tool' => $this->tool('new_tool')]);

        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Tool, 'old_tool', remoteId: 'twl_old', remoteName: 'old_tool', lastAppliedPayload: ['name' => 'old_tool'], status: ManagedResourceStatus::Applied));

        $reader = new FakeRemoteReader(tools: [$this->remoteTool('old_tool')]);

        $plan = $this->plan($compiled, $reader, $store, new SyncOptions(prune: true));

        $ids = array_map(static fn (SyncOperation $op): string => $op->id, $plan->operations);

        $this->assertSame(['tool:new_tool', 'tool:old_tool'], $ids);
        $this->assertSame(SyncClassification::Create, $plan->operations[0]->classification);
        $this->assertSame(SyncClassification::OrphanManaged, $plan->operations[1]->classification);
        $this->assertSame(SyncAction::Delete, $plan->operations[1]->action);
    }

    public function test_synced_team_with_remote_ids_is_unchanged(): void
    {
        $compiled = $this->compiled(
            agents: [
                'orchestrator' => $this->agent('orchestrator'),
                'researcher' => $this->agent('researcher'),
            ],
            teams: ['forecasting' => $this->team('forecasting', 'orchestrator', members: [$this->member('researcher', 'research')])],
        );

        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Agent, 'orchestrator', remoteId: 'agt_1', remoteName: 'orchestrator', lastAppliedPayload: $this->agent('orchestrator')->toRemotePayload(), status: ManagedResourceStatus::Applied));
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Agent, 'researcher', remoteId: 'agt_2', remoteName: 'researcher', lastAppliedPayload: $this->agent('researcher')->toRemotePayload(), status: ManagedResourceStatus::Applied));
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Team, 'forecasting', remoteId: 'team_1', remoteName: 'forecasting', lastAppliedPayload: ['name' => 'forecasting', 'orchestrator_id' => 'orchestrator', 'members' => [['agent_definition_id' => 'researcher', 'role' => 'research']]], status: ManagedResourceStatus::Applied));

        $reader = new FakeRemoteReader(
            agents: [$this->remoteAgent('orchestrator', ['id' => 'agt_1']), $this->remoteAgent('researcher', ['id' => 'agt_2'])],
            teams: [$this->remoteTeam('forecasting', 'agt_1', [['agent_definition_id' => 'agt_2', 'role' => 'research']])],
        );

        $plan = $this->plan($compiled, $reader, $store);

        $teamOp = $plan->operations[2];

        $this->assertSame(SyncClassification::Unchanged, $teamOp->classification);
        $this->assertSame(SyncAction::None, $teamOp->action);
    }

    public function test_new_team_references_local_keys_in_desired_payload(): void
    {
        $compiled = $this->compiled(
            agents: ['orchestrator' => $this->agent('orchestrator')],
            teams: ['forecasting' => $this->team('forecasting', 'orchestrator')],
        );

        $plan = $this->plan($compiled);

        $teamOp = $plan->operations[1];

        $this->assertSame(['name' => 'forecasting', 'orchestrator_id' => 'orchestrator', 'members' => []], $teamOp->desiredPayload);
    }

    public function test_planner_does_not_acquire_the_lock(): void
    {
        $store = new InMemorySyncStateStore;
        $lock = $store->acquireLock(self::C);

        try {
            $plan = $this->plan($this->compiled(tools: ['a' => $this->tool('a')]), new FakeRemoteReader, $store);

            $this->assertSame(SyncClassification::Create, $plan->operations[0]->classification);
        } finally {
            $lock->release();
        }
    }

    public function test_adoption_of_a_team_shows_member_diff(): void
    {
        $compiled = $this->compiled(
            agents: ['orchestrator' => $this->agent('orchestrator'), 'researcher' => $this->agent('researcher')],
            teams: ['forecasting' => $this->team('forecasting', 'orchestrator', members: [$this->member('researcher')])],
        );

        $reader = new FakeRemoteReader(
            agents: [$this->remoteAgent('orchestrator', ['id' => 'agt_1']), $this->remoteAgent('researcher', ['id' => 'agt_2'])],
            teams: [$this->remoteTeam('forecasting', 'agt_1')],
        );

        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Agent, 'orchestrator', remoteId: 'agt_1', remoteName: 'orchestrator', status: ManagedResourceStatus::Applied));
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Agent, 'researcher', remoteId: 'agt_2', remoteName: 'researcher', status: ManagedResourceStatus::Applied));

        $plan = $this->plan($compiled, $reader, $store, new SyncOptions(adopt: true));

        $teamOp = $plan->operations[2];

        $this->assertSame(SyncClassification::AdoptionRequired, $teamOp->classification);
        $this->assertSame(SyncAction::Adopt, $teamOp->action);
        $this->assertSame('members[0]', $teamOp->diff?->entries[0]->path);
    }

    public function test_unchanged_operation_carries_the_stored_remote_marker(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $store = $this->storeWithAppliedTool('query_db', $compiled);

        $reader = new FakeRemoteReader(tools: [$this->remoteTool('query_db')]);

        $plan = $this->plan($compiled, $reader, $store);

        $this->assertSame('2026-01-01T00:00:00Z', $plan->operations[0]->remoteMarker);
    }

    /**
     * @param  array<string, ToolDefinition>  ...
     */
    private function storeWithAppliedTool(string $key, DefinitionCompilationResult $compiled): InMemorySyncStateStore
    {
        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(
            connection: self::C,
            type: ResourceType::Tool,
            localKey: $key,
            remoteId: 'twl_'.$key,
            remoteName: $key,
            desiredHash: 'sha256:stored',
            lastAppliedPayload: $compiled->manifest->tool($key)->toRemotePayload(),
            remoteMarker: '2026-01-01T00:00:00Z',
            synchronizedAt: new \DateTimeImmutable,
            status: ManagedResourceStatus::Applied,
        ));

        return $store;
    }

    private function plan(
        DefinitionCompilationResult $compiled,
        ?FakeRemoteReader $reader = null,
        ?SyncStateStore $store = null,
        ?SyncOptions $options = null,
    ): SyncPlan {
        return (new SyncPlanner($reader ?? new FakeRemoteReader, $store ?? new InMemorySyncStateStore))
            ->plan($compiled, $options ?? new SyncOptions, self::C);
    }

    private function operation(SyncPlan $plan, string $id): SyncOperation
    {
        foreach ($plan->operations as $op) {
            if ($op->id === $id) {
                return $op;
            }
        }

        $this->fail(sprintf('No operation with id "%s" in the plan.', $id));
    }
}
