<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\Reconciler;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Definitions\Compiler\DefinitionCompilationResult;
use Digitalygo\Envai\Definitions\Manifest\CanonicalManifestHasher;
use Digitalygo\Envai\Definitions\Tool\ToolDefinition;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Sync\Client\ClientRemoteWriter;
use Digitalygo\Envai\Sync\Planner\SyncAction;
use Digitalygo\Envai\Sync\Planner\SyncOptions;
use Digitalygo\Envai\Sync\Planner\SyncPlanner;
use Digitalygo\Envai\Sync\Planner\SyncScope;
use Digitalygo\Envai\Sync\Reconciler\SyncOperationOutcome;
use Digitalygo\Envai\Sync\Reconciler\SyncReconciler;
use Digitalygo\Envai\Sync\Reconciler\SyncReport;
use Digitalygo\Envai\Sync\Reconciler\SyncReportStatus;
use Digitalygo\Envai\Sync\State\File\FileSyncStateStore;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Sync\State\SyncLock;
use Digitalygo\Envai\Tests\Client\FakeTransport;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteReader;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteWriter;
use Digitalygo\Envai\Tests\Sync\SyncTestCase;

final class SyncReconcilerTest extends SyncTestCase
{
    private const C = 'production';

    public function test_create_runs_in_tool_agent_team_order(): void
    {
        $compiled = $this->compiled(
            tools: ['query_db' => $this->tool('query_db')],
            agents: ['researcher' => $this->agent('researcher')],
            teams: ['forecasting' => $this->team('forecasting', 'researcher')],
        );

        $report = $this->apply($compiled, new FakeRemoteReader, new FakeRemoteWriter);

        $writer = $this->lastWriter;

        $this->assertSame(['createTool', 'createAgent', 'createTeam'], array_map(static fn (array $c): string => $c['method'], $writer->calls));
        $this->assertSame(SyncReportStatus::Succeeded, $report->status);
        $this->assertTrue($report->manifestReady);
        $this->assertSame(['tool:query_db', 'agent:researcher', 'team:forecasting'], array_map(static fn ($r): string => $r->operationId, $report->results));
    }

    public function test_create_saves_applied_state_with_remote_identity(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);

        $this->apply($compiled, new FakeRemoteReader, new FakeRemoteWriter);

        $state = $this->lastStore->getResource(self::C, ResourceType::Tool, 'query_db');

        $this->assertNotNull($state);
        $this->assertSame('twl_query_db', $state->remoteId);
        $this->assertSame('query_db', $state->remoteName);
        $this->assertSame('2026-08-28T10:00:00Z', $state->remoteMarker);
        $this->assertSame(ManagedResourceStatus::Applied, $state->status);
        $this->assertInstanceOf(\DateTimeImmutable::class, $state->synchronizedAt);
        $this->assertSame($compiled->manifest->tool('query_db')->toRemotePayload(), $state->lastAppliedPayload);
        $this->assertSame(
            (new CanonicalManifestHasher)->resourceHash('tool', 'query_db', $compiled->manifest->tool('query_db')->toRemotePayload()),
            $state->desiredHash,
        );
    }

    public function test_create_team_payload_uses_remote_agent_ids_from_same_apply(): void
    {
        $compiled = $this->compiled(
            agents: ['orchestrator' => $this->agent('orchestrator'), 'researcher' => $this->agent('researcher')],
            teams: ['forecasting' => $this->team('forecasting', 'orchestrator', members: [$this->member('researcher', 'research')])],
        );

        $this->apply($compiled, new FakeRemoteReader, new FakeRemoteWriter);

        $createTeam = $this->lastWriter->calls[2];

        $this->assertSame('createTeam', $createTeam['method']);
        $this->assertSame('agt_orchestrator', $createTeam['payload']['orchestrator_id']);
        $this->assertSame('agt_researcher', $createTeam['payload']['members'][0]['agent_definition_id']);
    }

    public function test_update_calls_update_and_refreshes_state(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db', timeoutSeconds: 60)]);
        $store = $this->storeWithAppliedTool('query_db', $this->compiled(tools: ['query_db' => $this->tool('query_db')]));
        $writer = new FakeRemoteWriter;
        $writer->seed('tool:query_db', $this->remoteTool('query_db'));

        $report = $this->apply($compiled, new FakeRemoteReader(tools: [$this->remoteTool('query_db')]), $writer, $store);

        $this->assertSame('updateTool', $writer->calls[0]['method']);
        $this->assertSame('tool:query_db', $writer->calls[0]['target']);
        $this->assertSame(60, $writer->calls[0]['payload']['timeout_seconds']);
        $this->assertSame(SyncReportStatus::Succeeded, $report->status);
        $this->assertTrue($report->manifestReady);

        $state = $store->getResource(self::C, ResourceType::Tool, 'query_db');

        $this->assertSame(60, $state?->lastAppliedPayload['timeout_seconds']);
        $this->assertSame(ManagedResourceStatus::Applied, $state?->status);
    }

    public function test_unchanged_produces_no_writer_calls_and_readiness(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $store = $this->storeWithAppliedTool('query_db', $compiled);
        $writer = new FakeRemoteWriter;

        $report = $this->apply($compiled, new FakeRemoteReader(tools: [$this->remoteTool('query_db')]), $writer, $store);

        $this->assertSame([], $writer->calls);
        $this->assertSame(SyncReportStatus::Succeeded, $report->status);
        $this->assertTrue($report->manifestReady);
        $this->assertSame($compiled->manifest->hash, $store->readyManifest(self::C)?->manifestHash);
    }

    public function test_adoption_registers_remote_as_last_applied_with_desired_hash(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $writer = new FakeRemoteWriter;
        $remote = $this->remoteTool('query_db', ['description' => 'Existing remotely']);
        $store = new InMemorySyncStateStore;

        $report = $this->apply($compiled, new FakeRemoteReader(tools: [$remote]), $writer, $store, new SyncOptions(adopt: true));

        $this->assertSame([], $writer->calls);
        $this->assertSame(SyncReportStatus::Succeeded, $report->status);
        $this->assertFalse($report->manifestReady);
        $this->assertNull($store->readyManifest(self::C));

        $state = $store->getResource(self::C, ResourceType::Tool, 'query_db');

        $this->assertNotNull($state);
        $this->assertSame('twl_query_db', $state->remoteId);
        $this->assertSame('Existing remotely', $state->lastAppliedPayload['description']);
        $this->assertSame(
            (new CanonicalManifestHasher)->resourceHash('tool', 'query_db', $compiled->manifest->tool('query_db')->toRemotePayload()),
            $state->desiredHash,
        );
    }

    public function test_adopt_with_overwrite_drift_performs_the_update_in_the_same_apply(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $writer = new FakeRemoteWriter;
        $remote = $this->remoteTool('query_db', ['description' => 'Existing remotely']);
        $writer->seed('tool:query_db', $remote);
        $store = new InMemorySyncStateStore;

        $report = $this->apply($compiled, new FakeRemoteReader(tools: [$remote]), $writer, $store, new SyncOptions(adopt: true, overwriteDrift: true));

        $this->assertCount(1, $writer->calls);
        $this->assertSame('updateTool', $writer->calls[0]['method']);
        $this->assertSame(SyncReportStatus::Succeeded, $report->status);

        $state = $store->getResource(self::C, ResourceType::Tool, 'query_db');

        $this->assertSame('Tool query_db', $state?->lastAppliedPayload['description']);
        $this->assertSame(ManagedResourceStatus::Applied, $state?->status);
    }

    public function test_drift_blocks_without_writer_calls(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $store = $this->storeWithAppliedTool('query_db', $compiled);
        $writer = new FakeRemoteWriter;

        $report = $this->apply($compiled, new FakeRemoteReader(tools: [$this->remoteTool('query_db', ['description' => 'Drifted'])]), $writer, $store);

        $this->assertSame([], $writer->calls);
        $this->assertSame(SyncReportStatus::Blocked, $report->status);
        $this->assertFalse($report->manifestReady);
        $this->assertSame(SyncOperationOutcome::Skipped, $report->results[0]->outcome);
    }

    public function test_drift_with_overwrite_drift_updates(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $store = $this->storeWithAppliedTool('query_db', $compiled);
        $writer = new FakeRemoteWriter;
        $writer->seed('tool:query_db', $this->remoteTool('query_db', ['description' => 'Drifted']));

        $report = $this->apply($compiled, new FakeRemoteReader(tools: [$this->remoteTool('query_db', ['description' => 'Drifted'])]), $writer, $store, new SyncOptions(overwriteDrift: true));

        $this->assertSame('updateTool', $writer->calls[0]['method']);
        $this->assertSame(SyncReportStatus::Succeeded, $report->status);
        $this->assertTrue($report->manifestReady);
    }

    public function test_conflict_blocks_always_even_with_all_overrides(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db', timeoutSeconds: 60)]);
        $store = $this->storeWithAppliedTool('query_db', $this->compiled(tools: ['query_db' => $this->tool('query_db')]));
        $writer = new FakeRemoteWriter;

        $report = $this->apply(
            $compiled,
            new FakeRemoteReader(tools: [$this->remoteTool('query_db', ['description' => 'Remote changed', 'timeout_seconds' => 45])]),
            $writer,
            $store,
            new SyncOptions(adopt: true, overwriteDrift: true, recreateMissing: true, prune: true),
        );

        $this->assertSame([], $writer->calls);
        $this->assertSame(SyncReportStatus::Blocked, $report->status);
        $this->assertFalse($report->manifestReady);
    }

    public function test_missing_remote_blocks_by_default(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $store = $this->storeWithAppliedTool('query_db', $compiled);

        $report = $this->apply($compiled, new FakeRemoteReader, new FakeRemoteWriter, $store);

        $this->assertSame([], $this->lastWriter->calls);
        $this->assertSame(SyncReportStatus::Blocked, $report->status);
        $this->assertFalse($report->manifestReady);
    }

    public function test_missing_remote_with_recreate_creates_again(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $store = $this->storeWithAppliedTool('query_db', $compiled);
        $writer = new FakeRemoteWriter;

        $report = $this->apply($compiled, new FakeRemoteReader, $writer, $store, new SyncOptions(recreateMissing: true));

        $this->assertSame('createTool', $writer->calls[0]['method']);
        $this->assertSame(SyncReportStatus::Succeeded, $report->status);
        $this->assertTrue($report->manifestReady);
    }

    public function test_orphan_without_prune_is_skipped(): void
    {
        $compiled = $this->compiled();
        $store = $this->storeWithOrphan('tool', 'old_tool', 'twl_old');
        $writer = new FakeRemoteWriter;

        $report = $this->apply($compiled, new FakeRemoteReader(tools: [$this->remoteTool('old_tool')]), $writer, $store);

        $this->assertSame([], $writer->calls);
        $this->assertSame(SyncOperationOutcome::Skipped, $report->results[0]->outcome);
        $this->assertSame(SyncReportStatus::Succeeded, $report->status);
    }

    public function test_orphan_with_prune_deletes_in_team_agent_tool_order(): void
    {
        $compiled = $this->compiled();
        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Tool, 'ztool', remoteId: 'twl_z', remoteName: 'ztool'));
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Agent, 'bagent', remoteId: 'agt_b', remoteName: 'bagent'));
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Team, 'ateam', remoteId: 'team_a', remoteName: 'ateam'));
        $writer = new FakeRemoteWriter;

        $report = $this->apply($compiled, new FakeRemoteReader, $writer, $store, new SyncOptions(prune: true));

        $this->assertSame([
            ['method' => 'deleteTeam', 'target' => 'team:team_a'],
            ['method' => 'deleteAgent', 'target' => 'agent:agt_b'],
            ['method' => 'deleteTool', 'target' => 'tool:ztool'],
        ], array_map(static fn (array $c): array => ['method' => $c['method'], 'target' => $c['target']], $writer->calls));

        $this->assertSame(SyncReportStatus::Succeeded, $report->status);
        $this->assertNull($store->getResource(self::C, ResourceType::Tool, 'ztool'));
        $this->assertNull($store->getResource(self::C, ResourceType::Agent, 'bagent'));
        $this->assertNull($store->getResource(self::C, ResourceType::Team, 'ateam'));
    }

    public function test_unmanaged_remote_is_never_deleted(): void
    {
        $compiled = $this->compiled();
        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Tool, 'managed_orphan', remoteId: 'twl_m', remoteName: 'managed_orphan'));
        $writer = new FakeRemoteWriter;

        // The unmanaged tool is present remotely but has no state record.
        $reader = new FakeRemoteReader(tools: [
            $this->remoteTool('managed_orphan'),
            $this->remoteTool('unmanaged_tool'),
        ]);

        $report = $this->apply($compiled, $reader, $writer, $store, new SyncOptions(prune: true));

        $this->assertSame([['method' => 'deleteTool', 'target' => 'tool:managed_orphan']], array_map(static fn (array $c): array => ['method' => $c['method'], 'target' => $c['target']], $writer->calls));
        $this->assertSame(SyncReportStatus::Succeeded, $report->status);
    }

    public function test_prune_deletes_by_stored_remote_name_not_local_key(): void
    {
        $compiled = $this->compiled();
        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Tool, 'legacy_key', remoteId: 'twl_actual_remote', remoteName: 'actual_name', status: ManagedResourceStatus::Applied));
        $writer = new FakeRemoteWriter;

        $this->apply($compiled, new FakeRemoteReader(tools: [$this->remoteTool('actual_name', ['id' => 'twl_actual_remote'])]), $writer, $store, new SyncOptions(prune: true));

        $this->assertSame('tool:actual_name', $writer->calls[0]['target']);
    }

    public function test_prune_never_deletes_unmanaged_despite_name_collision(): void
    {
        $compiled = $this->compiled();
        $writer = new FakeRemoteWriter;

        // The store does not know this remote: nothing may delete it.
        $report = $this->apply($compiled, new FakeRemoteReader(tools: [$this->remoteTool('unmanaged')]), $writer, new InMemorySyncStateStore, new SyncOptions(prune: true));

        $this->assertSame([], $writer->calls);
        $this->assertSame(SyncReportStatus::Succeeded, $report->status);
    }

    public function test_prune_delete_failure_reports_api_error_and_blocks_readiness(): void
    {
        $compiled = $this->compiled();
        $store = $this->storeWithOrphan('team', 'stuck', 'team_stuck');
        $writer = new FakeRemoteWriter;
        $writer->scriptFailure('deleteTeam:team_stuck', FakeRemoteWriter::validationError('Team has active chats.'));

        $report = $this->apply($compiled, new FakeRemoteReader, $writer, $store, new SyncOptions(prune: true));

        $this->assertSame(SyncOperationOutcome::Failed, $report->results[0]->outcome);
        $this->assertSame(SyncReportStatus::Failed, $report->status);
        $this->assertFalse($report->manifestReady);
        $this->assertSame('validation_error', $report->results[0]->apiError?->type);
        $this->assertNotNull($store->getResource(self::C, ResourceType::Team, 'stuck'));
    }

    public function test_ambiguous_transport_with_equal_remote_treated_as_succeeded(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $writer = new FakeRemoteWriter;
        $writer->scriptFailure('createTool:query_db', FakeRemoteWriter::transportError());
        // The server actually applied the mutation despite the transport error.
        $writer->seed('tool:query_db', $this->remoteTool('query_db'));

        $report = $this->apply($compiled, new FakeRemoteReader, $writer, new InMemorySyncStateStore);

        $this->assertCount(1, $writer->calls);
        $this->assertSame(SyncOperationOutcome::Succeeded, $report->results[0]->outcome);
        $this->assertSame(SyncReportStatus::Succeeded, $report->status);
        $this->assertTrue($report->manifestReady);

        $state = $this->lastStore->getResource(self::C, ResourceType::Tool, 'query_db');

        $this->assertSame(ManagedResourceStatus::Applied, $state?->status);
        $this->assertSame('twl_query_db', $state?->remoteId);
    }

    public function test_ambiguous_transport_with_different_remote_blocks_fail_closed(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $writer = new FakeRemoteWriter;
        $writer->scriptFailure('createTool:query_db', FakeRemoteWriter::transportError());
        // The remote holds a different payload: the outcome is unprovable.
        $writer->seed('tool:query_db', $this->remoteTool('query_db', ['description' => 'Something else']));

        $report = $this->apply($compiled, new FakeRemoteReader, $writer, new InMemorySyncStateStore);

        $this->assertCount(1, $writer->calls);
        $this->assertSame(SyncOperationOutcome::Ambiguous, $report->results[0]->outcome);
        $this->assertSame(SyncReportStatus::Blocked, $report->status);
        $this->assertFalse($report->manifestReady);
        $this->assertNull($this->lastStore->readyManifest(self::C));

        $state = $this->lastStore->getResource(self::C, ResourceType::Tool, 'query_db');

        $this->assertSame(ManagedResourceStatus::Ambiguous, $state?->status);
    }

    public function test_ambiguous_transport_with_missing_remote_blocks_fail_closed(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $writer = new FakeRemoteWriter;
        $writer->scriptFailure('createTool:query_db', FakeRemoteWriter::transportError());

        $report = $this->apply($compiled, new FakeRemoteReader, $writer, new InMemorySyncStateStore);

        $this->assertCount(1, $writer->calls);
        $this->assertSame(SyncOperationOutcome::Ambiguous, $report->results[0]->outcome);
        $this->assertSame(SyncReportStatus::Blocked, $report->status);
        $this->assertFalse($report->manifestReady);
    }

    public function test_lost_agent_and_team_create_responses_use_unique_name_http_readback(): void
    {
        foreach (['agent', 'team', 'server-agent', 'server-team'] as $variant) {
            foreach (['equal', 'different', 'absent', 'duplicate'] as $outcome) {
                $serverError = str_starts_with($variant, 'server-');
                $kind = str_replace('server-', '', $variant);
                $agent = $this->agent('orchestrator');
                $compiled = $kind === 'agent'
                    ? $this->compiled(agents: ['orchestrator' => $agent])
                    : $this->compiled(agents: ['orchestrator' => $agent], teams: ['group' => $this->team('group', 'orchestrator')]);
                $store = new InMemorySyncStateStore;
                $remoteAgent = $this->remoteAgent('orchestrator');
                if ($kind === 'team') {
                    $store->saveResource(new ManagedResourceState(self::C, ResourceType::Agent, 'orchestrator', remoteId: $remoteAgent['id'], remoteName: 'orchestrator', lastAppliedPayload: $agent->toRemotePayload(), status: ManagedResourceStatus::Applied));
                }
                $reader = new FakeRemoteReader(agents: $kind === 'team' ? [$remoteAgent] : []);
                $plan = (new SyncPlanner($reader, $store))->plan($compiled, new SyncOptions, self::C);
                $transport = new FakeTransport;
                if ($serverError) {
                    $transport->queueResponse(new HttpResponse(503, [], '{"error":{"type":"server_error","message":"uncertain"}}'));
                } else {
                    $transport->queueException(FakeRemoteWriter::transportError('Lost create response.'));
                }
                $remote = $kind === 'agent' ? $remoteAgent : $this->remoteTeam('group', $remoteAgent['id']);
                if ($outcome === 'different') {
                    $remote['description'] = 'different';
                }
                $matches = $outcome === 'absent' ? [] : ($outcome === 'duplicate' ? [$remote, array_replace($remote, ['id' => 'duplicate-id'])] : [$remote]);
                $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => $matches])));
                $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('synthetic')), $transport);
                $writer = new ClientRemoteWriter($client->tools(), $client->agents(), $client->teams());
                $report = (new SyncReconciler($writer, $store))->apply($plan, $compiled->manifest);
                self::assertSame($outcome === 'equal', $report->manifestReady, $kind.' '.$outcome);
                self::assertCount(2, $transport->requests);
                self::assertSame(['POST', 'GET'], array_column($transport->requests, 'method'));
                self::assertSame('/api/v1/'.$kind.'s', parse_url($transport->requests[1]->uri, PHP_URL_PATH));
            }
        }
    }

    public function test_recovered_agent_identity_is_substituted_into_following_team_http_create(): void
    {
        $compiled = $this->compiled(agents: ['orchestrator' => $this->agent('orchestrator')], teams: ['group' => $this->team('group', 'orchestrator')]);
        $store = new InMemorySyncStateStore;
        $plan = (new SyncPlanner(new FakeRemoteReader, $store))->plan($compiled, new SyncOptions, self::C);
        $agent = $this->remoteAgent('orchestrator', ['id' => 'actual-returned-agent-id']);
        $transport = new FakeTransport;
        $transport->queueException(FakeRemoteWriter::transportError('Lost response.'));
        $transport->queueResponse(new HttpResponse(200, [], json_encode(['data' => [$agent]])));
        $transport->queueResponse(new HttpResponse(201, [], json_encode(['data' => $this->remoteTeam('group', $agent['id'])])));
        $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('synthetic')), $transport);
        $writer = new ClientRemoteWriter($client->tools(), $client->agents(), $client->teams());
        $report = (new SyncReconciler($writer, $store))->apply($plan, $compiled->manifest);
        self::assertTrue($report->manifestReady);
        self::assertCount(3, $transport->requests);
        self::assertSame($agent['id'], json_decode($transport->requests[2]->body, true)['orchestrator_id']);
    }

    public function test_durable_intent_precedes_remote_mutation_and_survives_unexpected_crash(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $path = sys_get_temp_dir().'/envai-sync-intent-'.bin2hex(random_bytes(8));
        try {
            $store = new FileSyncStateStore($path);
            $writer = new FakeRemoteWriter;
            $writer->beforeMutation = static function () use ($path): void {
                $reopened = new FileSyncStateStore($path);
                self::assertSame(ManagedResourceStatus::Ambiguous, $reopened->getResource(self::C, ResourceType::Tool, 'query_db')->status);
                throw new \RuntimeException('Synthetic process interruption before response persistence.');
            };
            $plan = (new SyncPlanner(new FakeRemoteReader, $store))->plan($compiled, new SyncOptions, self::C);
            $report = (new SyncReconciler($writer, $store))->apply($plan, $compiled->manifest);
            self::assertFalse($report->manifestReady);
            $reopened = new FileSyncStateStore($path);
            $retry = (new SyncPlanner(new FakeRemoteReader, $reopened))->plan($compiled, new SyncOptions(recreateMissing: true, overwriteDrift: true), self::C);
            self::assertSame('ambiguous_outcome', $retry->operations[0]->reasonCode);
            self::assertSame([], $retry->executable());
            self::assertNull($reopened->readyManifest(self::C));
            $confirmed = (new SyncPlanner(new FakeRemoteReader(tools: [$this->remoteTool('query_db')]), $reopened))->plan($compiled, new SyncOptions, self::C);
            self::assertSame(SyncAction::Recover, $confirmed->operations[0]->action);
            $recovery = (new SyncReconciler($writer, $reopened))->apply($confirmed, $compiled->manifest);
            self::assertTrue($recovery->manifestReady);
            self::assertSame(ManagedResourceStatus::Applied, $reopened->getResource(self::C, ResourceType::Tool, 'query_db')->status);
            self::assertSame([], $writer->calls);
        } finally {
            foreach (glob($path.'*') ?: [] as $file) {
                unlink($file);
            }
        }
    }

    public function test_confirmed_delete_recovers_durable_intent_without_another_delete(): void
    {
        $compiled = $this->compiled();
        $store = $this->storeWithOrphan('tool', 'old_tool', 'old_id');
        $writer = new FakeRemoteWriter;
        $writer->seed('tool:old_tool', $this->remoteTool('old_tool'));
        $writer->scriptFailure('deleteTool:old_tool', FakeRemoteWriter::transportError());
        $first = $this->apply($compiled, new FakeRemoteReader(tools: [$this->remoteTool('old_tool')]), $writer, $store, new SyncOptions(prune: true));
        self::assertFalse($first->manifestReady);
        self::assertSame(ManagedResourceStatus::Ambiguous, $store->getResource(self::C, ResourceType::Tool, 'old_tool')->status);
        $confirmed = $this->apply($compiled, new FakeRemoteReader, $writer, $store, new SyncOptions(prune: true));
        self::assertTrue($confirmed->manifestReady);
        self::assertCount(1, $writer->calls);
        self::assertNull($store->getResource(self::C, ResourceType::Tool, 'old_tool'));
    }

    public function test_ambiguous_state_blocks_future_mutation_and_clears_prior_readiness(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $store = new InMemorySyncStateStore;
        $store->markManifestReady(self::C, $compiled->manifest->hash);
        $writer = new FakeRemoteWriter;
        $writer->scriptFailure('createTool:query_db', FakeRemoteWriter::transportError());
        $first = $this->apply($compiled, new FakeRemoteReader, $writer, $store);
        self::assertFalse($first->manifestReady);
        self::assertNull($store->readyManifest(self::C));
        self::assertCount(1, $writer->calls);
        $second = $this->apply($compiled, new FakeRemoteReader, $writer, $store, new SyncOptions(adopt: true, overwriteDrift: true, recreateMissing: true, prune: true));
        self::assertSame(SyncReportStatus::Blocked, $second->status);
        self::assertFalse($second->manifestReady);
        self::assertNull($store->readyManifest(self::C));
        self::assertCount(1, $writer->calls);
        self::assertSame(ManagedResourceStatus::Ambiguous, $store->getResource(self::C, ResourceType::Tool, 'query_db')->status);
    }

    public function test_ambiguous_transport_on_delete_with_remote_gone_is_succeeded(): void
    {
        $compiled = $this->compiled();
        $store = $this->storeWithOrphan('tool', 'old_tool', 'twl_old');
        $writer = new FakeRemoteWriter;
        $writer->scriptFailure('deleteTool:old_tool', FakeRemoteWriter::transportError());

        $report = $this->apply($compiled, new FakeRemoteReader(tools: [$this->remoteTool('old_tool')]), $writer, $store, new SyncOptions(prune: true));

        $this->assertSame(SyncOperationOutcome::Succeeded, $report->results[0]->outcome);
        $this->assertNull($store->getResource(self::C, ResourceType::Tool, 'old_tool'));
    }

    public function test_partial_apply_stops_after_api_failure_and_keeps_first_result(): void
    {
        $compiled = $this->compiled(
            tools: ['good_tool' => $this->tool('good_tool'), 'bad_tool' => $this->tool('bad_tool')],
        );
        $writer = new FakeRemoteWriter;
        $writer->scriptFailure('createTool:bad_tool', FakeRemoteWriter::validationError());
        $store = new InMemorySyncStateStore;

        $report = $this->apply($compiled, new FakeRemoteReader, $writer, $store);

        $this->assertSame(SyncOperationOutcome::Succeeded, $report->results[0]->outcome);
        $this->assertSame(SyncOperationOutcome::Failed, $report->results[1]->outcome);
        $this->assertSame(SyncReportStatus::Failed, $report->status);
        $this->assertFalse($report->manifestReady);

        $this->assertNotNull($store->getResource(self::C, ResourceType::Tool, 'good_tool'));
        $this->assertNull($store->getResource(self::C, ResourceType::Tool, 'bad_tool'));
        $this->assertCount(2, $writer->calls);
    }

    public function test_partial_apply_interrupted_by_unexpected_exception(): void
    {
        $compiled = $this->compiled(
            tools: ['good_tool' => $this->tool('good_tool'), 'explosive_tool' => $this->tool('explosive_tool')],
        );
        $writer = new FakeRemoteWriter;
        $writer->scriptFailure('createTool:explosive_tool', new \RuntimeException('Unexpected boom.'));
        $store = new InMemorySyncStateStore;

        $report = $this->apply($compiled, new FakeRemoteReader, $writer, $store);

        $this->assertSame(SyncOperationOutcome::Succeeded, $report->results[0]->outcome);
        $this->assertSame(SyncOperationOutcome::Failed, $report->results[1]->outcome);
        $this->assertSame(SyncReportStatus::Partial, $report->status);
        $this->assertFalse($report->manifestReady);
        $this->assertNull($store->readyManifest(self::C));
    }

    public function test_partial_scope_never_marks_manifest_ready(): void
    {
        $compiled = $this->compiled(
            tools: ['query_db' => $this->tool('query_db')],
            agents: ['researcher' => $this->agent('researcher')],
        );

        $report = $this->apply($compiled, new FakeRemoteReader, new FakeRemoteWriter, null, new SyncOptions(scope: SyncScope::Tools));

        $this->assertSame(SyncReportStatus::Succeeded, $report->status);
        $this->assertFalse($report->manifestReady);
        $this->assertNull($this->lastStore->readyManifest(self::C));

        $agentOutcome = $report->results[1]->outcome;

        $this->assertSame(SyncOperationOutcome::Skipped, $agentOutcome);
    }

    public function test_dry_run_mutates_nothing_and_never_marks_ready(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $writer = new FakeRemoteWriter;
        $store = new InMemorySyncStateStore;

        $report = $this->apply($compiled, new FakeRemoteReader, $writer, $store, new SyncOptions(dryRun: true));

        $this->assertSame([], $writer->calls);
        $this->assertSame(SyncReportStatus::DryRun, $report->status);
        $this->assertFalse($report->manifestReady);
        $this->assertSame(SyncOperationOutcome::Pending, $report->results[0]->outcome);
        $this->assertNull($store->readyManifest(self::C));
        $this->assertNull($store->getResource(self::C, ResourceType::Tool, 'query_db'));
    }

    public function test_rename_agent_updates_by_stored_remote_id(): void
    {
        $compiled = $this->compiled(agents: ['orchestrator' => $this->agent('orchestrator', name: 'New name')]);
        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Agent, 'orchestrator', remoteId: 'agt_keep', remoteName: 'Old name', lastAppliedPayload: $this->agent('orchestrator', name: 'Old name')->toRemotePayload(), status: ManagedResourceStatus::Applied));
        $writer = new FakeRemoteWriter;
        $writer->seed('agent:agt_keep', $this->remoteAgent('Old name', ['id' => 'agt_keep']));

        $report = $this->apply($compiled, new FakeRemoteReader(agents: [$this->remoteAgent('Old name', ['id' => 'agt_keep'])]), $writer, $store);

        $this->assertSame('updateAgent', $writer->calls[0]['method']);
        $this->assertSame('agent:agt_keep', $writer->calls[0]['target']);
        $this->assertSame('New name', $writer->calls[0]['payload']['name']);
        $this->assertSame(SyncReportStatus::Succeeded, $report->status);

        $state = $store->getResource(self::C, ResourceType::Agent, 'orchestrator');

        $this->assertSame('agt_keep', $state?->remoteId);
        $this->assertSame('New name', $state?->lastAppliedPayload['name']);
    }

    public function test_tool_rename_creates_new_then_prunes_old(): void
    {
        $compiled = $this->compiled(tools: ['new_tool' => $this->tool('new_tool')]);
        $store = $this->storeWithOrphan('tool', 'old_tool', 'twl_old');
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Tool, 'old_tool', remoteId: 'twl_old', remoteName: 'old_tool', lastAppliedPayload: ['name' => 'old_tool'], status: ManagedResourceStatus::Applied));
        $writer = new FakeRemoteWriter;

        $report = $this->apply($compiled, new FakeRemoteReader(tools: [$this->remoteTool('old_tool')]), $writer, $store, new SyncOptions(prune: true));

        $this->assertSame('createTool', $writer->calls[0]['method']);
        $this->assertSame('new_tool', $writer->calls[0]['payload']['name']);
        $this->assertSame('deleteTool', $writer->calls[1]['method']);
        $this->assertSame('tool:old_tool', $writer->calls[1]['target']);
        $this->assertSame(SyncReportStatus::Succeeded, $report->status);
    }

    public function test_report_json_shape_is_stable(): void
    {
        $compiled = $this->compiled(
            tools: ['query_db' => $this->tool('query_db')],
            agents: ['researcher' => $this->agent('researcher')],
        );

        $report = $this->apply($compiled, new FakeRemoteReader, new FakeRemoteWriter);

        $data = $report->toArray();

        $this->assertSame(1, $data['report_format']);
        $this->assertSame(self::C, $data['connection']);
        $this->assertSame($compiled->manifest->hash, $data['manifest_hash']);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $data['plan_hash']);
        $this->assertSame('succeeded', $data['status']);
        $this->assertTrue($data['manifest_ready']);
        $this->assertCount(2, $data['results']);
        $this->assertSame('tool:query_db', $data['results'][0]['operation_id']);
        $this->assertSame('succeeded', $data['results'][0]['outcome']);
        $this->assertSame('twl_query_db', $data['results'][0]['remote_id']);
        $this->assertSame(2, $data['summary']['total']);
        $this->assertSame(2, $data['summary']['succeeded']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/',
            $data['started_at'],
        );
    }

    public function test_report_serializes_api_error_envelope(): void
    {
        $compiled = $this->compiled(tools: ['bad_tool' => $this->tool('bad_tool')]);
        $writer = new FakeRemoteWriter;
        $writer->scriptFailure('createTool:bad_tool', FakeRemoteWriter::validationError('Nope.'));

        $report = $this->apply($compiled, new FakeRemoteReader, $writer, new InMemorySyncStateStore);

        $data = $report->toArray();
        $apiError = $data['results'][0]['api_error'];

        $this->assertSame('validation_error', $apiError['type']);
        $this->assertSame('invalid_payload', $apiError['code']);
        $this->assertSame('Nope.', $apiError['message']);
        $this->assertSame('failed', $data['status']);
    }

    public function test_lock_is_released_after_apply(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);

        $report = $this->apply($compiled, new FakeRemoteReader, new FakeRemoteWriter);

        $this->assertSame(SyncReportStatus::Succeeded, $report->status);

        // The reconciler acquired and released the lock itself.
        $lock = $this->lastStore->acquireLock(self::C);
        $lock->release();

        $this->addToAssertionCount(1);
    }

    public function test_lock_is_released_after_failed_apply(): void
    {
        $compiled = $this->compiled(tools: ['bad_tool' => $this->tool('bad_tool')]);
        $writer = new FakeRemoteWriter;
        $writer->scriptFailure('createTool:bad_tool', FakeRemoteWriter::validationError());
        $store = new InMemorySyncStateStore;

        $this->apply($compiled, new FakeRemoteReader, $writer, $store);

        $lock = $store->acquireLock(self::C);
        $lock->release();

        $this->addToAssertionCount(1);
    }

    public function test_preacquired_lock_is_not_released_by_the_reconciler(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $store = new InMemorySyncStateStore;
        $lock = $store->acquireLock(self::C);

        $this->apply($compiled, new FakeRemoteReader, new FakeRemoteWriter, $store, null, $lock);

        $this->assertFalse($lock->isReleased());

        $lock->release();
    }

    public function test_blocked_apply_does_not_mark_manifest_ready(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $store = $this->storeWithAppliedTool('query_db', $compiled);

        $report = $this->apply($compiled, new FakeRemoteReader(tools: [$this->remoteTool('query_db', ['description' => 'Drifted'])]), new FakeRemoteWriter, $store);

        $this->assertSame(SyncReportStatus::Blocked, $report->status);
        $this->assertNull($store->readyManifest(self::C));
    }

    public function test_adoption_sets_desired_hash_of_desired_payload(): void
    {
        $compiled = $this->compiled(tools: ['query_db' => $this->tool('query_db')]);
        $writer = new FakeRemoteWriter;
        $remote = $this->remoteTool('query_db', ['description' => 'Different']);
        $store = new InMemorySyncStateStore;

        $this->apply($compiled, new FakeRemoteReader(tools: [$remote]), $writer, $store, new SyncOptions(adopt: true));

        $state = $store->getResource(self::C, ResourceType::Tool, 'query_db');

        $this->assertNotSame(
            (new CanonicalManifestHasher)->resourceHash('tool', 'query_db', ['name' => 'query_db', 'kind' => 'client', 'description' => 'Different']),
            $state?->desiredHash,
        );
        $this->assertSame(
            (new CanonicalManifestHasher)->resourceHash('tool', 'query_db', $compiled->manifest->tool('query_db')->toRemotePayload()),
            $state?->desiredHash,
        );
    }

    public function test_updated_team_payload_rewrites_agent_ids_from_live_map(): void
    {
        $compiled = $this->compiled(
            agents: ['orchestrator' => $this->agent('orchestrator'), 'researcher' => $this->agent('researcher')],
            teams: ['forecasting' => $this->team('forecasting', 'orchestrator', members: [$this->member('researcher')])],
        );
        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Agent, 'orchestrator', remoteId: 'agt_1', remoteName: 'orchestrator', lastAppliedPayload: $this->agent('orchestrator')->toRemotePayload(), status: ManagedResourceStatus::Applied));
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Agent, 'researcher', remoteId: 'agt_2', remoteName: 'researcher', lastAppliedPayload: $this->agent('researcher')->toRemotePayload(), status: ManagedResourceStatus::Applied));
        // The team is new: its members resolve from the live agent map.
        $writer = new FakeRemoteWriter;

        $this->apply($compiled, new FakeRemoteReader(agents: [
            $this->remoteAgent('orchestrator', ['id' => 'agt_1']),
            $this->remoteAgent('researcher', ['id' => 'agt_2']),
        ]), $writer, $store);

        $createTeam = $writer->calls[0];

        $this->assertSame('createTeam', $createTeam['method']);
        $this->assertSame('agt_1', $createTeam['payload']['orchestrator_id']);
        $this->assertSame('agt_2', $createTeam['payload']['members'][0]['agent_definition_id']);
    }

    public function test_team_with_unknown_agent_reference_reports_failed(): void
    {
        $compiled = $this->compiled(
            agents: ['orchestrator' => $this->agent('orchestrator')],
            teams: ['forecasting' => $this->team('forecasting', 'orchestrator', members: [$this->member('ghost')])],
        );
        // No agent state for 'ghost': the rewrite cannot resolve it.
        $store = new InMemorySyncStateStore;
        $writer = new FakeRemoteWriter;

        $report = $this->apply($compiled, new FakeRemoteReader, $writer, $store);

        $this->assertSame(SyncReportStatus::Partial, $report->status);
        $this->assertFalse($report->manifestReady);
        $this->assertSame('createAgent', $writer->calls[0]['method']);
        $this->assertStringContainsString('ghost', $report->results[1]->error ?? '');
    }

    public function test_all_result_outcomes_map_to_summary_counts(): void
    {
        $compiled = $this->compiled(
            tools: ['a' => $this->tool('a'), 'b' => $this->tool('b'), 'c' => $this->tool('c')],
        );
        $writer = new FakeRemoteWriter;
        $writer->scriptFailure('createTool:b', FakeRemoteWriter::transportError());

        $report = $this->apply($compiled, new FakeRemoteReader, $writer, new InMemorySyncStateStore);

        $this->assertSame(1, $report->summary['succeeded']);
        $this->assertSame(1, $report->summary['ambiguous']);
        $this->assertSame(1, $report->summary['skipped']);
        $this->assertSame(3, $report->summary['total']);
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

    private function storeWithOrphan(string $type, string $localKey, string $remoteId): InMemorySyncStateStore
    {
        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::from($type), $localKey, remoteId: $remoteId, remoteName: $localKey, status: ManagedResourceStatus::Applied));

        return $store;
    }

    private InMemorySyncStateStore $lastStore;

    private FakeRemoteWriter $lastWriter;

    private function apply(
        DefinitionCompilationResult $compiled,
        FakeRemoteReader $reader,
        FakeRemoteWriter $writer,
        ?InMemorySyncStateStore $store = null,
        ?SyncOptions $options = null,
        ?SyncLock $preacquired = null,
    ): SyncReport {
        $store ??= new InMemorySyncStateStore;
        $options ??= new SyncOptions;

        $plan = (new SyncPlanner($reader, $store))->plan($compiled, $options, self::C);
        $report = (new SyncReconciler($writer, $store))->apply($plan, $compiled->manifest, $preacquired);

        $this->lastStore = $store;
        $this->lastWriter = $writer;

        return $report;
    }
}
