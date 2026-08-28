<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync;

use Digitalygo\Envai\Definitions\Compiler\DefinitionCompiler;
use Digitalygo\Envai\Exceptions\DefinitionsNotSynchronizedException;
use Digitalygo\Envai\Sync\DefinitionsService;
use Digitalygo\Envai\Sync\Planner\SyncOptions;
use Digitalygo\Envai\Sync\Reconciler\SyncReportStatus;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ReadyManifest;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Tests\Definitions\Tool\Fixtures\FakeEchoTool;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteReader;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteWriter;

final class DefinitionsServiceTest extends SyncTestCase
{
    private const C = 'production';

    public function test_validate_is_a_pure_local_phase(): void
    {
        $path = $this->layout();
        $reader = new FakeRemoteReader;
        $store = new InMemorySyncStateStore;

        $service = $this->service($path, $reader, $store);
        $result = $service->validate();

        $this->assertTrue($result->valid);
        $this->assertSame([], $result->violations);
        $this->assertSame(0, $reader->toolsReads);
        $this->assertSame(0, $reader->agentsReads);
        $this->assertSame(0, $reader->teamsReads);
        $this->assertSame(0, count(iterator_to_array($store->resources(self::C))));

        // No lock was taken, so a lock can still be acquired.
        $store->acquireLock(self::C)->release();
    }

    public function test_plan_acquires_and_releases_the_lock_and_reads_remote(): void
    {
        $path = $this->layout();
        $reader = new FakeRemoteReader;
        $store = new InMemorySyncStateStore;

        $service = $this->service($path, $reader, $store);
        $plan = $service->plan();

        $this->assertSame(4, count($plan->operations));
        $this->assertGreaterThan(0, $reader->toolsReads);
        $this->assertGreaterThan(0, $reader->agentsReads);
        $this->assertGreaterThan(0, $reader->teamsReads);

        // The plan was returned without holding the lock.
        $store->acquireLock(self::C)->release();
    }

    public function test_apply_plans_and_reconciles_under_one_lock(): void
    {
        $path = $this->layout();
        $writer = new FakeRemoteWriter;
        $store = new InMemorySyncStateStore;

        $service = $this->service($path, new FakeRemoteReader, $store, $writer);
        $report = $service->apply();

        $this->assertSame(SyncReportStatus::Succeeded, $report->status);
        $this->assertTrue($report->manifestReady);
        $this->assertSame(4, count($writer->calls));

        $ready = $store->readyManifest(self::C);

        $this->assertInstanceOf(ReadyManifest::class, $ready);
        $this->assertSame($service->manifest()->hash, $ready->manifestHash);

        // The lock was released after the apply.
        $store->acquireLock(self::C)->release();
    }

    public function test_require_synchronized_passes_when_ready_matches(): void
    {
        $path = $this->layout();
        $store = new InMemorySyncStateStore;

        $service = $this->service($path, new FakeRemoteReader, $store, new FakeRemoteWriter);
        $service->apply();

        $service->requireSynchronized();

        $this->addToAssertionCount(1);
    }

    public function test_require_synchronized_throws_when_never_synchronized(): void
    {
        $path = $this->layout();
        $service = $this->service($path, new FakeRemoteReader, new InMemorySyncStateStore);

        $this->expectException(DefinitionsNotSynchronizedException::class);

        $service->requireSynchronized();
    }

    public function test_require_synchronized_throws_on_manifest_mismatch(): void
    {
        $path = $this->layout();
        $store = new InMemorySyncStateStore;

        $service = $this->service($path, new FakeRemoteReader, $store, new FakeRemoteWriter);
        $service->apply();

        // Change a definition: the compiled hash now differs from the ready one.
        file_put_contents($path.'/agents/orchestrator.md', $this->agentMarkdown('Changed body.', "format: 1\nname: orchestrator\nmode: primary\n"));

        $this->expectException(DefinitionsNotSynchronizedException::class);

        $service->requireSynchronized();
    }

    public function test_team_agent_tool_resolution_after_sync(): void
    {
        $path = $this->layout();
        $store = new InMemorySyncStateStore;

        $service = $this->service($path, new FakeRemoteReader, $store, new FakeRemoteWriter);
        $service->apply();

        $this->assertSame('team_Forecasting team', $service->team('forecasting'));
        $this->assertSame('agt_orchestrator', $service->agent('orchestrator'));
        $this->assertSame('query_db', $service->tool('query_db'));
    }

    public function test_team_resolution_throws_when_never_synchronized(): void
    {
        $path = $this->layout();
        $service = $this->service($path, new FakeRemoteReader, new InMemorySyncStateStore);

        $this->expectException(DefinitionsNotSynchronizedException::class);

        $service->team('forecasting');
    }

    public function test_team_resolution_throws_when_unsynced_after_drift(): void
    {
        $path = $this->layout();
        $store = new InMemorySyncStateStore;

        $service = $this->service($path, new FakeRemoteReader, $store, new FakeRemoteWriter);
        $service->apply();

        // A local change invalidates readiness before the next sync.
        file_put_contents($path.'/agents/orchestrator.md', $this->agentMarkdown('Changed body.', "format: 1\nname: orchestrator\nmode: primary\n"));

        $this->expectException(DefinitionsNotSynchronizedException::class);

        $service->team('forecasting');
    }

    public function test_team_resolution_throws_when_state_missing(): void
    {
        $path = $this->layout();
        $store = new InMemorySyncStateStore;
        $store->markManifestReady(self::C, (new DefinitionCompiler)->compile($path)->manifest->hash);

        $service = $this->service($path, new FakeRemoteReader, $store);

        $this->expectException(DefinitionsNotSynchronizedException::class);

        $service->team('forecasting');
    }

    public function test_manifest_returns_the_compiled_manifest_without_gating(): void
    {
        $path = $this->layout();
        $service = $this->service($path, new FakeRemoteReader, new InMemorySyncStateStore);

        $manifest = $service->manifest();

        $this->assertSame(['query_db'], array_keys($manifest->tools));
        $this->assertSame(['orchestrator', 'researcher'], array_keys($manifest->agents));
        $this->assertSame(['forecasting'], array_keys($manifest->teams));
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $manifest->hash);
    }

    public function test_ready_manifest_is_null_before_sync_and_set_after(): void
    {
        $path = $this->layout();
        $store = new InMemorySyncStateStore;

        $service = $this->service($path, new FakeRemoteReader, $store, new FakeRemoteWriter);

        $this->assertNull($service->readyManifest());

        $service->apply();

        $this->assertNotNull($service->readyManifest());
        $this->assertSame(self::C, $service->readyManifest()?->connection);
    }

    public function test_dry_run_apply_does_not_mark_ready(): void
    {
        $path = $this->layout();
        $writer = new FakeRemoteWriter;
        $store = new InMemorySyncStateStore;

        $service = $this->service($path, new FakeRemoteReader, $store, $writer);
        $report = $service->apply(new SyncOptions(dryRun: true));

        $this->assertSame(SyncReportStatus::DryRun, $report->status);
        $this->assertFalse($report->manifestReady);
        $this->assertSame([], $writer->calls);
        $this->assertNull($store->readyManifest(self::C));
    }

    public function test_blocked_apply_leaves_connection_unready(): void
    {
        $path = $this->layout();
        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(self::C, ResourceType::Tool, 'query_db', remoteId: 'twl_query_db', remoteName: 'query_db', lastAppliedPayload: ['name' => 'query_db', 'kind' => 'client', 'description' => 'Tool query_db', 'parameters' => ['type' => 'object', 'properties' => []], 'timeout_seconds' => 30], status: ManagedResourceStatus::Applied));

        $reader = new FakeRemoteReader(tools: [$this->remoteTool('query_db', ['description' => 'Remote drifted'])]);

        $service = $this->service($path, $reader, $store);
        $report = $service->apply();

        $this->assertSame(SyncReportStatus::Blocked, $report->status);
        $this->assertFalse($report->manifestReady);
        $this->assertNull($store->readyManifest(self::C));
    }

    public function test_plan_respects_options(): void
    {
        $path = $this->layout();
        $service = $this->service($path, new FakeRemoteReader, new InMemorySyncStateStore);

        $plan = $service->plan(new SyncOptions(dryRun: true));

        $this->assertTrue($plan->options->dryRun);
        $this->assertSame('production', $plan->connection);
    }

    public function test_custom_connection_is_used_for_state(): void
    {
        $path = $this->layout();
        $store = new InMemorySyncStateStore;

        $service = new DefinitionsService(
            new DefinitionCompiler,
            $store,
            new FakeRemoteReader,
            new FakeRemoteWriter,
            connection: 'staging',
            definitionsPath: $path,
        );

        $service->apply();

        $this->assertNotNull($store->readyManifest('staging'));
        $this->assertNull($store->readyManifest('production'));
        $this->assertSame('staging', $service->readyManifest()?->connection);
    }

    public function test_apply_after_previous_sync_is_idempotent(): void
    {
        $path = $this->layout();
        $writer = new FakeRemoteWriter;
        $store = new InMemorySyncStateStore;

        $service = $this->service($path, new FakeRemoteReader, $store, $writer);

        $first = $service->apply();

        // The remote now reflects what the first apply created; a fresh
        // reader sees the created resources and the second plan
        // classifies everything unchanged.
        $service = $this->service($path, $this->readerFromServer($writer), $store, $writer);
        $second = $service->apply();

        $this->assertTrue($first->manifestReady);
        $this->assertTrue($second->manifestReady);
        $this->assertCount(4, $writer->calls);
        $this->assertSame(SyncReportStatus::Succeeded, $second->status);
    }

    private function readerFromServer(FakeRemoteWriter $writer): FakeRemoteReader
    {
        $tools = [];
        $agents = [];
        $teams = [];

        foreach ($writer->server as $key => $payload) {
            if (str_starts_with($key, 'tool:')) {
                $tools[] = $payload;
            } elseif (str_starts_with($key, 'agent:')) {
                $agents[] = $payload;
            } else {
                $teams[] = $payload;
            }
        }

        return new FakeRemoteReader($tools, $agents, $teams);
    }

    private function layout(): string
    {
        return $this->makeEnvaiLayout(
            agents: [
                'orchestrator' => $this->agentMarkdown('You orchestrate.', "format: 1\nname: orchestrator\nmode: primary\n"),
                'researcher' => $this->agentMarkdown('You research.', "format: 1\nname: researcher\nmode: subagent\n"),
            ],
            teams: [
                'forecasting' => $this->teamPhp('forecasting', "\n    ->name('Forecasting team')\n    ->orchestrator('orchestrator')\n    ->member('researcher')"),
            ],
            tools: $this->toolPhp('query_db', FakeEchoTool::class),
        );
    }

    private function service(string $path, FakeRemoteReader $reader, InMemorySyncStateStore $store, ?FakeRemoteWriter $writer = null): DefinitionsService
    {
        return new DefinitionsService(
            new DefinitionCompiler,
            $store,
            $reader,
            $writer ?? new FakeRemoteWriter,
            connection: self::C,
            definitionsPath: $path,
        );
    }
}
