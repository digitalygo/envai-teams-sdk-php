<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\Console;

use Digitalygo\Envai\Sync\Console\DefinitionsStatusCommand;
use Digitalygo\Envai\Sync\Console\DefinitionsSyncCommand;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteReader;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteWriter;

final class DefinitionsStatusCommandTest extends ConsoleTestCase
{
    public function test_never_synchronized_reports_no_and_exits_one(): void
    {
        $harness = $this->validated(DefinitionsStatusCommand::class, new FakeRemoteReader, new FakeRemoteWriter, new InMemorySyncStateStore);

        $output = $harness['tester']->getDisplay();

        $this->assertSame(1, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('Synchronized: no', $output);
        $this->assertStringContainsString('Ready:      none', $output);
        $this->assertMatchesRegularExpression('/Manifest:   sha256:[0-9a-f]{64}/', $output);
    }

    public function test_synchronized_after_apply_exits_zero_with_ok_drift(): void
    {
        $writer = new FakeRemoteWriter;
        $store = new InMemorySyncStateStore;

        $first = $this->validated(DefinitionsSyncCommand::class, new FakeRemoteReader, $writer, $store);
        $this->assertSame(0, $first['tester']->getStatusCode());

        $tester = $this->command(DefinitionsStatusCommand::class, $this->readerFromServer($writer), $writer, $store);
        $tester->execute([
            '--connection' => self::CONNECTION,
            '--definitions-path' => $first['path'],
            '--state-driver' => 'inmemory',
        ]);

        $output = $tester->getDisplay();

        $this->assertSame(0, $tester->getStatusCode());
        $this->assertStringContainsString('Synchronized: yes', $output);
        $this->assertStringContainsString('tool:query_db', $output);
        $this->assertStringContainsString('applied', $output);
        $this->assertStringContainsString('twl_query_db', $output);
        $this->assertStringContainsString('ok', $output);
    }

    public function test_local_change_after_apply_reports_unsynchronized_and_changed_drift(): void
    {
        $writer = new FakeRemoteWriter;
        $store = new InMemorySyncStateStore;

        $first = $this->validated(DefinitionsSyncCommand::class, new FakeRemoteReader, $writer, $store);
        $this->assertSame(0, $first['tester']->getStatusCode());

        // Change a local definition after the apply: the manifest hash moves.
        $path = $first['path'];
        file_put_contents($path.'/agents/researcher.md', $this->agentMarkdown('Changed body.', "format: 1\nname: researcher\nmode: subagent\n"));

        $tester = $this->command(DefinitionsStatusCommand::class, $this->readerFromServer($writer), $writer, $store);
        $tester->execute([
            '--connection' => self::CONNECTION,
            '--definitions-path' => $path,
            '--state-driver' => 'inmemory',
        ]);

        $output = $tester->getDisplay();

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Synchronized: no', $output);
        $this->assertStringContainsString('agent:researcher', $output);
        $this->assertStringContainsString('changed', $output);
    }

    public function test_status_works_without_definitions_directory(): void
    {
        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(
            self::CONNECTION,
            ResourceType::Tool,
            'query_db',
            remoteId: 'twl_query_db',
            remoteName: 'query_db',
            desiredHash: 'sha256:old',
            status: ManagedResourceStatus::Applied,
        ));

        $tester = $this->command(DefinitionsStatusCommand::class, new FakeRemoteReader, new FakeRemoteWriter, $store);

        $tester->execute([
            '--connection' => self::CONNECTION,
            '--definitions-path' => '/tmp/definitely-not-present-'.bin2hex(random_bytes(4)),
            '--state-driver' => 'inmemory',
        ]);

        $output = $tester->getDisplay();

        $this->assertSame(1, $tester->getStatusCode());
        $this->assertStringContainsString('Manifest:   unavailable', $output);
        $this->assertStringContainsString('Synchronized: no', $output);
        $this->assertStringContainsString('tool:query_db', $output);
        $this->assertStringContainsString('n/a', $output);
    }

    public function test_status_json_shape(): void
    {
        $writer = new FakeRemoteWriter;
        $store = new InMemorySyncStateStore;

        $this->validated(DefinitionsSyncCommand::class, new FakeRemoteReader, $writer, $store);

        $harness = $this->validated(
            DefinitionsStatusCommand::class,
            $this->readerFromServer($writer),
            $writer,
            $store,
            ['--json' => true],
        );
        $document = json_decode($harness['tester']->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(1, $document['status_format']);
        $this->assertSame(self::CONNECTION, $document['connection']);
        $this->assertTrue($document['synchronized']);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $document['manifest_hash']);
        $this->assertSame($document['manifest_hash'], $document['ready_manifest_hash']);
        $this->assertCount(4, $document['resources']);

        $tool = $document['resources'][0];

        $this->assertSame('tool:query_db', $tool['key']);
        $this->assertSame('applied', $tool['status']);
        $this->assertSame('twl_query_db', $tool['remote_id']);
        $this->assertSame('ok', $tool['drift']);
    }

    public function test_status_with_stored_ambiguous_resource_reports_unsynchronized(): void
    {
        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(
            self::CONNECTION,
            ResourceType::Tool,
            'ghost_tool',
            remoteId: 'twl_ghost',
            remoteName: 'ghost_tool',
            desiredHash: 'sha256:old',
            status: ManagedResourceStatus::Ambiguous,
        ));
        $store->markManifestReady(self::CONNECTION, 'sha256:'.str_repeat('f', 64));

        $harness = $this->validated(DefinitionsStatusCommand::class, new FakeRemoteReader, new FakeRemoteWriter, $store);

        $output = $harness['tester']->getDisplay();

        $this->assertSame(1, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('Synchronized: no', $output);
        $this->assertStringContainsString('ghost_tool', $output);
        $this->assertStringContainsString('ambiguous', $output);
        $this->assertStringContainsString('changed', $output);
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
