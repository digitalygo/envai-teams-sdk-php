<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\Console;

use Digitalygo\Envai\Sync\Console\DefinitionsPlanCommand;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteReader;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteWriter;

final class DefinitionsPlanCommandTest extends ConsoleTestCase
{
    private const ECHO_DESCRIPTION = 'Echoes the given text back.';

    public function test_create_plan_text_uses_plus_lines_per_section(): void
    {
        $harness = $this->validated(DefinitionsPlanCommand::class, new FakeRemoteReader, new FakeRemoteWriter, new InMemorySyncStateStore);

        $output = $harness['tester']->getDisplay();

        $this->assertSame(0, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('Connection: production', $output);
        $this->assertMatchesRegularExpression('/Manifest:   sha256:[0-9a-f]{64}/', $output);
        $this->assertStringContainsString('Tools'."\n".'  + query_db', $output);
        $this->assertStringContainsString('Agents'."\n".'  + orchestrator'."\n".'  + researcher', $output);
        $this->assertStringContainsString('Teams'."\n".'  + forecasting', $output);
        $this->assertStringNotContainsString('Conflicts', $output);
    }

    public function test_update_plan_text_uses_tilde_lines(): void
    {
        $reader = new FakeRemoteReader(
            tools: [$this->remoteDesiredTool('query_db', 'Old description')],
        );
        $store = new InMemorySyncStateStore;
        $store->saveResource($this->appliedTool('query_db', 'Old description'));

        $harness = $this->validated(DefinitionsPlanCommand::class, $reader, new FakeRemoteWriter, $store);

        $output = $harness['tester']->getDisplay();

        $this->assertSame(0, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('  ~ query_db', $output);
    }

    public function test_unchanged_plan_text_uses_equals_lines(): void
    {
        $reader = new FakeRemoteReader(
            tools: [$this->remoteDesiredTool('query_db')],
        );
        $store = new InMemorySyncStateStore;
        $store->saveResource($this->appliedTool('query_db'));

        $harness = $this->validated(DefinitionsPlanCommand::class, $reader, new FakeRemoteWriter, $store);

        $this->assertStringContainsString('  = query_db', $harness['tester']->getDisplay());
    }

    public function test_prune_plan_text_uses_minus_lines(): void
    {
        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(
            self::CONNECTION,
            ResourceType::Tool,
            'retired_tool',
            remoteId: 'twl_retired',
            remoteName: 'retired_tool',
            desiredHash: 'sha256:old',
            lastAppliedPayload: ['name' => 'retired_tool', 'kind' => 'client', 'parameters' => ['type' => 'object', 'properties' => []], 'timeout_seconds' => 30],
            status: ManagedResourceStatus::Applied,
        ));

        $harness = $this->validated(DefinitionsPlanCommand::class, new FakeRemoteReader, new FakeRemoteWriter, $store, ['--prune' => true]);

        $output = $harness['tester']->getDisplay();

        $this->assertSame(0, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('  - retired_tool', $output);
    }

    public function test_blocked_drift_reports_conflicts_section_and_exits_one(): void
    {
        $reader = new FakeRemoteReader(
            tools: [$this->remoteTool('query_db', ['description' => 'Remote drifted'])],
        );
        $store = new InMemorySyncStateStore;
        $store->saveResource($this->appliedTool('query_db'));

        $harness = $this->validated(DefinitionsPlanCommand::class, $reader, new FakeRemoteWriter, $store);

        $output = $harness['tester']->getDisplay();

        $this->assertSame(1, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('Conflicts', $output);
        $this->assertStringContainsString('  ! query_db: remote changed after last apply', $output);
    }

    public function test_json_shape_is_stable(): void
    {
        $harness = $this->validated(
            DefinitionsPlanCommand::class,
            new FakeRemoteReader,
            new FakeRemoteWriter,
            new InMemorySyncStateStore,
            ['--json' => true],
        );

        $document = json_decode($harness['tester']->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $document['plan_format']);
        $this->assertSame(self::CONNECTION, $document['connection']);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $document['manifest_hash']);
        $this->assertSame('all', $document['scope']);
        $this->assertCount(1, $document['tools']);
        $this->assertCount(2, $document['agents']);
        $this->assertCount(1, $document['teams']);
        $this->assertSame([], $document['conflicts']);

        $tool = $document['tools'][0];

        $this->assertSame('tool:query_db', $tool['id']);
        $this->assertSame('create', $tool['action']);
        $this->assertSame('create', $tool['classification']);
        $this->assertFalse($tool['blocking']);
    }

    public function test_only_tools_restricts_the_plan(): void
    {
        $harness = $this->validated(
            DefinitionsPlanCommand::class,
            new FakeRemoteReader,
            new FakeRemoteWriter,
            new InMemorySyncStateStore,
            ['--only' => ['tools'], '--json' => true],
        );

        $document = json_decode($harness['tester']->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('tools', $document['scope']);
        $this->assertCount(1, $document['tools']);
        $this->assertCount(2, $document['agents']);
        $this->assertCount(1, $document['teams']);

        foreach ([...$document['agents'], ...$document['teams']] as $operation) {
            $this->assertSame('none', $operation['action']);
            $this->assertSame('out_of_scope', $operation['reason_code']);
        }
    }

    public function test_only_with_contradicting_kinds_exits_two(): void
    {
        $harness = $this->validated(
            DefinitionsPlanCommand::class,
            new FakeRemoteReader,
            new FakeRemoteWriter,
            new InMemorySyncStateStore,
            ['--only' => ['tools', 'agents']],
        );

        $this->assertSame(2, $harness['tester']->getStatusCode());
    }

    public function test_fail_on_drift_without_drift_exits_zero(): void
    {
        $harness = $this->validated(
            DefinitionsPlanCommand::class,
            new FakeRemoteReader,
            new FakeRemoteWriter,
            new InMemorySyncStateStore,
            ['--fail-on-drift' => true],
        );

        $this->assertSame(0, $harness['tester']->getStatusCode());
    }

    public function test_fail_on_drift_with_drift_exits_two(): void
    {
        $reader = new FakeRemoteReader(
            tools: [$this->remoteTool('query_db', ['description' => 'Remote drifted'])],
        );
        $store = new InMemorySyncStateStore;
        $store->saveResource($this->appliedTool('query_db'));

        $harness = $this->validated(
            DefinitionsPlanCommand::class,
            $reader,
            new FakeRemoteWriter,
            $store,
            ['--fail-on-drift' => true],
        );

        $this->assertSame(2, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('Drift detected', $harness['tester']->getDisplay());
    }

    public function test_missing_definitions_directory_exits_two(): void
    {
        $tester = $this->command(DefinitionsPlanCommand::class, new FakeRemoteReader, new FakeRemoteWriter, new InMemorySyncStateStore);

        $tester->execute([
            '--connection' => self::CONNECTION,
            '--definitions-path' => '/tmp/definitely-not-present-'.bin2hex(random_bytes(4)),
            '--state-driver' => 'inmemory',
        ]);

        $this->assertSame(2, $tester->getStatusCode());
    }

    public function test_plan_never_mutates_the_writer(): void
    {
        $writer = new FakeRemoteWriter;

        $harness = $this->validated(DefinitionsPlanCommand::class, new FakeRemoteReader, $writer, new InMemorySyncStateStore);

        $this->assertSame(0, $harness['tester']->getStatusCode());
        $this->assertSame([], $writer->calls);
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
}
