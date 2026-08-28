<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\Console;

use Digitalygo\Envai\Sync\Console\DefinitionsStateCommand;
use Digitalygo\Envai\Sync\Console\DefinitionsSyncCommand;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteReader;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteWriter;

final class DefinitionsStateCommandTest extends ConsoleTestCase
{
    public function test_json_document_shape_after_sync(): void
    {
        $writer = new FakeRemoteWriter;
        $store = new InMemorySyncStateStore;

        $this->validated(DefinitionsSyncCommand::class, new FakeRemoteReader, $writer, $store);

        $harness = $this->validated(
            DefinitionsStateCommand::class,
            $this->readerFromServer($writer),
            $writer,
            $store,
            ['--json' => true],
        );

        $document = json_decode($harness['tester']->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $harness['tester']->getStatusCode());
        $this->assertSame(1, $document['state_format']);
        $this->assertSame(self::CONNECTION, $document['connection']);
        $this->assertNotNull($document['manifest']);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $document['manifest']['manifest_hash']);
        $this->assertCount(4, $document['resources']);

        $tool = $document['resources'][0];

        $this->assertSame('tool', $tool['type']);
        $this->assertSame('query_db', $tool['local_key']);
        $this->assertSame('twl_query_db', $tool['remote_id']);
        $this->assertSame('applied', $tool['status']);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $tool['desired_hash']);
    }

    public function test_credentials_in_metadata_are_masked_in_json(): void
    {
        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(
            self::CONNECTION,
            ResourceType::Tool,
            'query_db',
            remoteId: 'twl_query_db',
            remoteName: 'query_db',
            desiredHash: 'sha256:'.str_repeat('a', 64),
            status: ManagedResourceStatus::Applied,
            metadata: ['api_key' => 'sk-secret', 'access_token' => 'tok-123', 'team_note' => 'visible'],
        ));

        $harness = $this->validated(
            DefinitionsStateCommand::class,
            new FakeRemoteReader,
            new FakeRemoteWriter,
            $store,
            ['--json' => true],
        );

        $document = json_decode($harness['tester']->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $metadata = $document['resources'][0]['metadata'];

        $this->assertSame('***', $metadata['api_key']);
        $this->assertSame('***', $metadata['access_token']);
        $this->assertSame('visible', $metadata['team_note']);

        $encoded = $harness['tester']->getDisplay();

        $this->assertStringNotContainsString('sk-secret', $encoded);
        $this->assertStringNotContainsString('tok-123', $encoded);
    }

    public function test_local_key_identity_is_not_masked(): void
    {
        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(
            self::CONNECTION,
            ResourceType::Agent,
            'researcher',
            remoteId: 'agt_researcher',
            remoteName: 'researcher',
            desiredHash: 'sha256:'.str_repeat('b', 64),
            status: ManagedResourceStatus::Applied,
        ));

        $harness = $this->validated(
            DefinitionsStateCommand::class,
            new FakeRemoteReader,
            new FakeRemoteWriter,
            $store,
            ['--json' => true],
        );

        $document = json_decode($harness['tester']->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('researcher', $document['resources'][0]['local_key']);
        $this->assertSame('agt_researcher', $document['resources'][0]['remote_id']);
    }

    public function test_table_output_renders_resource_rows(): void
    {
        $store = new InMemorySyncStateStore;
        $store->saveResource(new ManagedResourceState(
            self::CONNECTION,
            ResourceType::Tool,
            'query_db',
            remoteId: 'twl_query_db',
            remoteName: 'query_db',
            desiredHash: 'sha256:'.str_repeat('a', 64),
            status: ManagedResourceStatus::Applied,
        ));

        $harness = $this->validated(DefinitionsStateCommand::class, new FakeRemoteReader, new FakeRemoteWriter, $store);

        $output = $harness['tester']->getDisplay();

        $this->assertSame(0, $harness['tester']->getStatusCode());
        $this->assertStringContainsString('Connection: '.self::CONNECTION, $output);
        $this->assertStringContainsString('query_db', $output);
        $this->assertStringContainsString('applied', $output);
        $this->assertStringContainsString('twl_query_db', $output);
        $this->assertStringContainsString('Local key', $output);
    }

    public function test_empty_store_reports_none(): void
    {
        $harness = $this->validated(
            DefinitionsStateCommand::class,
            new FakeRemoteReader,
            new FakeRemoteWriter,
            new InMemorySyncStateStore,
            ['--json' => true],
        );

        $document = json_decode($harness['tester']->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertNull($document['manifest']);
        $this->assertSame([], $document['resources']);
    }

    public function test_works_without_definitions_directory(): void
    {
        $store = new InMemorySyncStateStore;
        $store->markManifestReady(self::CONNECTION, 'sha256:'.str_repeat('c', 64));

        $tester = $this->command(DefinitionsStateCommand::class, new FakeRemoteReader, new FakeRemoteWriter, $store);

        $tester->execute([
            '--connection' => self::CONNECTION,
            '--definitions-path' => '/tmp/definitely-not-present-'.bin2hex(random_bytes(4)),
            '--state-driver' => 'inmemory',
            '--json' => true,
        ]);

        $this->assertSame(0, $tester->getStatusCode());

        $document = json_decode($tester->getDisplay(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('sha256:'.str_repeat('c', 64), $document['manifest']['manifest_hash']);
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
