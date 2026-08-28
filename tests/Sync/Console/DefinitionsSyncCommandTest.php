<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\Console;

use Digitalygo\Envai\Sync\Console\DefinitionsSyncCommand;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteReader;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteWriter;

final class DefinitionsSyncCommandTest extends ConsoleTestCase
{
    private const ECHO_DESCRIPTION = 'Echoes the given text back.';

    public function test_full_sync_succeeds_and_marks_manifest_ready(): void
    {
        $writer = new FakeRemoteWriter;
        $store = new InMemorySyncStateStore;

        $harness = $this->validated(DefinitionsSyncCommand::class, new FakeRemoteReader, $writer, $store);

        $output = $harness['tester']->getDisplay();

        $this->assertSame(0, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('Status:     succeeded', $output);
        $this->assertStringContainsString('Manifest ready: yes', $output);
        $this->assertStringContainsString('Results:    4 succeeded', $output);
        $this->assertStringContainsString('Next: the readiness gate is open for connection "production".', $output);

        $this->assertCount(4, $writer->calls);
        $this->assertNotNull($store->readyManifest(self::CONNECTION));
        $this->assertSame(4, count(iterator_to_array($store->resources(self::CONNECTION))));
    }

    public function test_sync_json_shape(): void
    {
        $harness = $this->validated(
            DefinitionsSyncCommand::class,
            new FakeRemoteReader,
            new FakeRemoteWriter,
            new InMemorySyncStateStore,
            ['--json' => true],
        );

        $document = json_decode($harness['tester']->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $document['report_format']);
        $this->assertSame(self::CONNECTION, $document['connection']);
        $this->assertSame('succeeded', $document['status']);
        $this->assertTrue($document['manifest_ready']);
        $this->assertSame(4, $document['summary']['succeeded']);
        $this->assertCount(4, $document['results']);

        $tool = $document['results'][0];

        $this->assertSame('tool:query_db', $tool['operation_id']);
        $this->assertSame('succeeded', $tool['outcome']);
        $this->assertSame('twl_query_db', $tool['remote_id']);
    }

    public function test_dry_run_mutates_nothing(): void
    {
        $writer = new FakeRemoteWriter;
        $store = new InMemorySyncStateStore;

        $harness = $this->validated(
            DefinitionsSyncCommand::class,
            new FakeRemoteReader,
            $writer,
            $store,
            ['--dry-run' => true],
        );

        $output = $harness['tester']->getDisplay();

        $this->assertSame(0, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('Status:     dry_run', $output);
        $this->assertStringContainsString('Manifest ready: no', $output);
        $this->assertStringContainsString('Next: run envai definitions:sync (without --dry-run) to apply the plan.', $output);
        $this->assertSame([], $writer->calls);
        $this->assertNull($store->readyManifest(self::CONNECTION));
        $this->assertSame([], iterator_to_array($store->resources(self::CONNECTION)));
    }

    public function test_blocked_sync_exits_one_and_never_marks_ready(): void
    {
        $reader = new FakeRemoteReader(
            tools: [$this->remoteTool('query_db', ['description' => 'Remote drifted'])],
        );
        $store = new InMemorySyncStateStore;
        $store->saveResource($this->appliedTool('query_db'));

        $harness = $this->validated(DefinitionsSyncCommand::class, $reader, new FakeRemoteWriter, $store);

        $output = $harness['tester']->getDisplay();

        $this->assertSame(1, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('Status:     blocked', $output);
        $this->assertStringContainsString('Manifest ready: no', $output);
        $this->assertNull($store->readyManifest(self::CONNECTION));
    }

    public function test_fail_on_drift_with_drift_exits_two(): void
    {
        $reader = new FakeRemoteReader(
            tools: [$this->remoteTool('query_db', ['description' => 'Remote drifted'])],
        );
        $store = new InMemorySyncStateStore;
        $store->saveResource($this->appliedTool('query_db'));

        $harness = $this->validated(
            DefinitionsSyncCommand::class,
            $reader,
            new FakeRemoteWriter,
            $store,
            ['--fail-on-drift' => true],
        );

        $this->assertSame(2, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('Drift detected', $harness['tester']->getDisplay());
    }

    public function test_fail_on_drift_clean_sync_exits_zero(): void
    {
        $harness = $this->validated(
            DefinitionsSyncCommand::class,
            new FakeRemoteReader,
            new FakeRemoteWriter,
            new InMemorySyncStateStore,
            ['--fail-on-drift' => true],
        );

        $this->assertSame(0, $harness['tester']->getStatusCode());
    }

    public function test_only_tools_sync_is_partial_and_never_marks_ready(): void
    {
        $writer = new FakeRemoteWriter;
        $store = new InMemorySyncStateStore;

        $harness = $this->validated(
            DefinitionsSyncCommand::class,
            new FakeRemoteReader,
            $writer,
            $store,
            ['--only' => ['tools']],
        );

        $output = $harness['tester']->getDisplay();

        $this->assertSame(0, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('Manifest ready: no', $output);
        $this->assertStringContainsString('Next: the plan was partial (--only); run a full envai definitions:sync to mark the manifest ready.', $output);
        $this->assertCount(1, $writer->calls);
        $this->assertNull($store->readyManifest(self::CONNECTION));
    }

    public function test_failed_mutation_exits_one_with_error(): void
    {
        $writer = new FakeRemoteWriter;
        $writer->scriptFailure('createTool:query_db', FakeRemoteWriter::validationError('Tool name is taken.'));

        $harness = $this->validated(DefinitionsSyncCommand::class, new FakeRemoteReader, $writer, new InMemorySyncStateStore);

        $output = $harness['tester']->getDisplay();

        $this->assertSame(1, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('Status:     failed', $output);
        $this->assertStringContainsString('Manifest ready: no', $output);
    }

    public function test_missing_definitions_directory_exits_two(): void
    {
        $tester = $this->command(DefinitionsSyncCommand::class, new FakeRemoteReader, new FakeRemoteWriter, new InMemorySyncStateStore);

        $tester->execute([
            '--connection' => self::CONNECTION,
            '--definitions-path' => '/tmp/definitely-not-present-'.bin2hex(random_bytes(4)),
            '--state-driver' => 'inmemory',
        ]);

        $this->assertSame(2, $tester->getStatusCode());
        $this->assertStringContainsString('does not exist', $tester->getDisplay());
    }

    public function test_second_sync_is_idempotent(): void
    {
        $writer = new FakeRemoteWriter;
        $store = new InMemorySyncStateStore;

        $first = $this->validated(DefinitionsSyncCommand::class, new FakeRemoteReader, $writer, $store);
        $this->assertSame(0, $first['tester']->getStatusCode());

        // The remote now reflects the first apply; a second sync changes nothing.
        $second = $this->validated(DefinitionsSyncCommand::class, $this->readerFromServer($writer), $writer, $store);

        $output = $second['tester']->getDisplay();

        $this->assertSame(0, $second['tester']->getStatusCode());
        $this->assertStringContainsString('Status:     succeeded', $output);
        $this->assertStringContainsString('Manifest ready: yes', $output);
        $this->assertStringContainsString('Results:    4 skipped', $output);
    }

    public function test_overwrite_drift_recovers_a_drifted_apply(): void
    {
        $reader = new FakeRemoteReader(
            tools: [$this->remoteTool('query_db', ['description' => 'Remote drifted'])],
        );
        $store = new InMemorySyncStateStore;
        $store->saveResource($this->appliedTool('query_db'));

        $harness = $this->validated(
            DefinitionsSyncCommand::class,
            $reader,
            new FakeRemoteWriter,
            $store,
            ['--overwrite-drift' => true],
        );

        $this->assertSame(0, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('Manifest ready: yes', $harness['tester']->getDisplay());
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function appliedTool(string $name, ?string $description = null, array $overrides = []): ManagedResourceState
    {
        $tool = array_merge($this->desiredToolPayload($name, $description), $overrides);

        return new ManagedResourceState(
            self::CONNECTION,
            ResourceType::Tool,
            $name,
            remoteId: 'twl_'.$name,
            remoteName: $name,
            desiredHash: 'sha256:'.str_repeat('a', 64),
            lastAppliedPayload: $tool,
            remoteMarker: '2026-01-01T00:00:00Z',
            status: ManagedResourceStatus::Applied,
        );
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
}
