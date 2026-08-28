<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Digitalygo\Envai\Definitions\Compiler\DefinitionCompiler;
use Digitalygo\Envai\Integration\Laravel\LazySynchronizer;
use Digitalygo\Envai\Sync\DefinitionsService;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Tests\Definitions\Tool\Fixtures\FakeEchoTool;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteReader;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteWriter;
use Digitalygo\Envai\Tests\Sync\SyncTestCase;

/**
 * Unit tests of the lazy synchronization gate (project document section
 * 23.2). Illuminate-free: the LazySynchronizer only uses core classes
 * (DefinitionsService, InMemorySyncStateStore, the remote fakes), so
 * these tests run in every environment, with or without the Laravel
 * packages.
 *
 * Re-sync scenarios rebuild the definitions service over a reader built
 * from the fake writer's server state (the same pattern the
 * DefinitionsService tests use): after a successful apply the remote
 * reflects the created resources, so the second sync plans updates or
 * no-ops instead of missing remotes.
 */
final class LazySyncTest extends SyncTestCase
{
    private const C = 'production';

    public function test_off_mode_is_a_noop(): void
    {
        $store = new InMemorySyncStateStore;
        $reader = new FakeRemoteReader;
        $writer = new FakeRemoteWriter;
        $service = $this->service($store, $reader, $writer);

        (new LazySynchronizer)->ensureSynchronized($service, 'off', 300);

        $this->assertNull($store->readyManifest(self::C));
        $this->assertSame(0, $reader->toolsReads);
        $this->assertSame([], $writer->calls);
    }

    public function test_manual_mode_is_a_noop(): void
    {
        $store = new InMemorySyncStateStore;
        $reader = new FakeRemoteReader;
        $writer = new FakeRemoteWriter;
        $service = $this->service($store, $reader, $writer);

        (new LazySynchronizer)->ensureSynchronized($service, 'manual', 300);

        $this->assertNull($store->readyManifest(self::C));
        $this->assertSame([], $writer->calls);
    }

    public function test_deploy_mode_is_a_noop(): void
    {
        $store = new InMemorySyncStateStore;
        $reader = new FakeRemoteReader;
        $writer = new FakeRemoteWriter;
        $service = $this->service($store, $reader, $writer);

        (new LazySynchronizer)->ensureSynchronized($service, 'deploy', 300);

        $this->assertNull($store->readyManifest(self::C));
        $this->assertSame([], $writer->calls);
    }

    public function test_uppercase_mode_is_normalized(): void
    {
        $store = new InMemorySyncStateStore;
        $service = $this->service($store, new FakeRemoteReader, new FakeRemoteWriter);

        (new LazySynchronizer)->ensureSynchronized($service, 'OFF', 300);

        $this->assertNull($store->readyManifest(self::C));
    }

    public function test_unknown_mode_throws(): void
    {
        $service = $this->service(new InMemorySyncStateStore, new FakeRemoteReader, new FakeRemoteWriter);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown sync mode');

        (new LazySynchronizer)->ensureSynchronized($service, 'sometimes', 300);
    }

    public function test_lazy_syncs_when_never_synchronized(): void
    {
        $store = new InMemorySyncStateStore;
        $writer = new FakeRemoteWriter;
        $service = $this->service($store, new FakeRemoteReader, $writer);

        (new LazySynchronizer)->ensureSynchronized($service, 'lazy', 300);

        $ready = $store->readyManifest(self::C);

        $this->assertNotNull($ready);
        $this->assertSame($service->manifest()->hash, $ready->manifestHash);
        $this->assertNotSame([], $writer->calls);

        // The gate is now open: the readiness check passes.
        $service->requireSynchronized();
    }

    public function test_lazy_is_a_noop_when_ready_and_fresh(): void
    {
        $store = new InMemorySyncStateStore;
        $reader = new FakeRemoteReader;
        $writer = new FakeRemoteWriter;
        $service = $this->service($store, $reader, $writer);

        (new LazySynchronizer)->ensureSynchronized($service, 'lazy', 300);

        $reader->toolsReads = 0;
        $reader->agentsReads = 0;
        $reader->teamsReads = 0;
        $writer->calls = [];

        (new LazySynchronizer)->ensureSynchronized($service, 'lazy', 300);

        $this->assertSame(0, $reader->toolsReads);
        $this->assertSame(0, $reader->agentsReads);
        $this->assertSame(0, $reader->teamsReads);
        $this->assertSame([], $writer->calls);
    }

    public function test_lazy_resyncs_when_the_hash_differs(): void
    {
        $store = new InMemorySyncStateStore;
        $writer = new FakeRemoteWriter;
        $path = $this->layout();
        $service = $this->service($store, new FakeRemoteReader, $writer, $path);

        (new LazySynchronizer)->ensureSynchronized($service, 'lazy', 300);

        // Change a definition: the compiled hash no longer matches.
        file_put_contents($path.'/agents/orchestrator.md', $this->agentMarkdown('You orchestrate differently.', "format: 1\nname: orchestrator\nmode: primary\n"));

        // The remote now reflects the first apply; a fresh reader sees the
        // created resources.
        $service = $this->service($store, $this->readerFromServer($writer), $writer, $path);

        (new LazySynchronizer)->ensureSynchronized($service, 'lazy', 300);

        $ready = $store->readyManifest(self::C);

        $this->assertNotNull($ready);
        $this->assertSame($service->manifest()->hash, $ready->manifestHash);
        $this->assertNotSame([], $writer->calls);
    }

    public function test_lazy_resyncs_when_the_ttl_expired(): void
    {
        $store = new InMemorySyncStateStore;
        $writer = new FakeRemoteWriter;
        $path = $this->layout();
        $service = $this->service($store, new FakeRemoteReader, $writer, $path);

        (new LazySynchronizer)->ensureSynchronized($service, 'lazy', 300);

        $reader = $this->readerFromServer($writer);
        $service = $this->service($store, $reader, $writer, $path);
        $expired = $store->readyManifest(self::C)->synchronizedAt->modify('+301 seconds');

        (new LazySynchronizer(nowProvider: fn (): \DateTimeImmutable => $expired))
            ->ensureSynchronized($service, 'lazy', 300);

        $this->assertGreaterThan(0, $reader->toolsReads);
    }

    public function test_lazy_does_not_resync_inside_the_ttl(): void
    {
        $store = new InMemorySyncStateStore;
        $writer = new FakeRemoteWriter;
        $path = $this->layout();
        $service = $this->service($store, new FakeRemoteReader, $writer, $path);

        (new LazySynchronizer)->ensureSynchronized($service, 'lazy', 300);

        $writer->calls = [];
        $reader = $this->readerFromServer($writer);
        $service = $this->service($store, $reader, $writer, $path);
        $fresh = $store->readyManifest(self::C)->synchronizedAt->modify('+299 seconds');

        (new LazySynchronizer(nowProvider: fn (): \DateTimeImmutable => $fresh))
            ->ensureSynchronized($service, 'lazy', 300);

        $this->assertSame(0, $reader->toolsReads);
        $this->assertSame([], $writer->calls);
    }

    public function test_lazy_treats_the_ttl_boundary_as_expired(): void
    {
        $store = new InMemorySyncStateStore;
        $writer = new FakeRemoteWriter;
        $path = $this->layout();
        $service = $this->service($store, new FakeRemoteReader, $writer, $path);

        (new LazySynchronizer)->ensureSynchronized($service, 'lazy', 300);

        $reader = $this->readerFromServer($writer);
        $service = $this->service($store, $reader, $writer, $path);
        $atBoundary = $store->readyManifest(self::C)->synchronizedAt->modify('+300 seconds');

        (new LazySynchronizer(nowProvider: fn (): \DateTimeImmutable => $atBoundary))
            ->ensureSynchronized($service, 'lazy', 300);

        $this->assertGreaterThan(0, $reader->toolsReads);
    }

    public function test_lazy_applies_with_default_options_without_pruning(): void
    {
        $store = new InMemorySyncStateStore;
        $writer = new FakeRemoteWriter;
        $path = $this->layout();
        $service = $this->service($store, new FakeRemoteReader, $writer, $path);

        (new LazySynchronizer)->ensureSynchronized($service, 'lazy', 300);

        // Remove the tool from the definitions: with prune disabled the
        // undeclared managed resource must NOT be deleted.
        file_put_contents($path.'/tools.php', "<?php\n\nreturn [];");

        $service = $this->service($store, $this->readerFromServer($writer), $writer, $path);

        (new LazySynchronizer)->ensureSynchronized($service, 'lazy', 300);

        foreach ($writer->calls as $call) {
            $this->assertNotSame('deleteTool', $call['method']);
        }
    }

    public function test_lazy_propagates_lock_exceptions(): void
    {
        $store = new InMemorySyncStateStore;
        $service = $this->service($store, new FakeRemoteReader, new FakeRemoteWriter);

        // Another process holds the connection lock: the apply fails and
        // the exception propagates instead of being swallowed.
        $store->acquireLock(self::C);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('already held');

        (new LazySynchronizer)->ensureSynchronized($service, 'lazy', 300);
    }

    public function test_lazy_propagates_compile_exceptions(): void
    {
        $store = new InMemorySyncStateStore;
        $path = $this->layout();

        unlink($path.'/agents/orchestrator.md');
        rmdir($path.'/agents');

        $service = new DefinitionsService(
            compiler: new DefinitionCompiler,
            store: $store,
            remoteReader: new FakeRemoteReader,
            remoteWriter: new FakeRemoteWriter,
            connection: self::C,
            definitionsPath: $path,
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('missing the agents/ subdirectory');

        (new LazySynchronizer)->ensureSynchronized($service, 'lazy', 300);
    }

    public function test_lazy_releases_the_lock_after_syncing(): void
    {
        $store = new InMemorySyncStateStore;
        $service = $this->service($store, new FakeRemoteReader, new FakeRemoteWriter);

        (new LazySynchronizer)->ensureSynchronized($service, 'lazy', 300);

        // The apply released the store lock, so a fresh acquisition works.
        $store->acquireLock(self::C)->release();

        $this->addToAssertionCount(1);
    }

    private function service(InMemorySyncStateStore $store, FakeRemoteReader $reader, FakeRemoteWriter $writer, ?string $path = null): DefinitionsService
    {
        return new DefinitionsService(
            compiler: new DefinitionCompiler,
            store: $store,
            remoteReader: $reader,
            remoteWriter: $writer,
            connection: self::C,
            definitionsPath: $path ?? $this->layout(),
        );
    }

    private function layout(): string
    {
        return $this->makeEnvaiLayout(
            agents: [
                'orchestrator' => $this->agentMarkdown('You orchestrate.', "format: 1\nname: orchestrator\nmode: primary\n"),
            ],
            teams: [
                'forecasting' => $this->teamPhp('forecasting', "\n    ->name('Forecasting team')\n    ->orchestrator('orchestrator')"),
            ],
            tools: $this->toolPhp('query_db', FakeEchoTool::class),
        );
    }

    /**
     * Builds a reader that sees the current state of the fake writer's
     * server, mirroring the pattern of DefinitionsServiceTest.
     */
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
}
