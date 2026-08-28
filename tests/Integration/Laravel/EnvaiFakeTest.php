<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\DefinitionsNotSynchronizedException;
use Digitalygo\Envai\Integration\Laravel\Testing\EnvaiFake;
use Digitalygo\Envai\Sync\Client\ClientRemoteReader;
use Digitalygo\Envai\Sync\Client\ClientRemoteWriter;
use Digitalygo\Envai\Sync\DefinitionsService;
use Digitalygo\Envai\Sync\Reconciler\SyncReportStatus;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;
use Digitalygo\Envai\Tests\Definitions\Tool\Fixtures\FakeEchoTool;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteReader;
use Digitalygo\Envai\Tests\Sync\Fakes\FakeRemoteWriter;
use Digitalygo\Envai\Tests\Sync\SyncTestCase;

/**
 * Unit tests of the EnvaiFake test double factory. Illuminate-free: the
 * fake only wires core classes (EnvaiClient, DefinitionsService,
 * InMemorySyncStateStore, the remote fakes), so these tests run in every
 * environment; swap() is exercised against a minimal instance()-exposing
 * fake container.
 */
final class EnvaiFakeTest extends SyncTestCase
{
    private const C = 'production';

    public function test_make_wires_the_in_memory_store(): void
    {
        $fake = $this->fake();

        $service = $fake->definitions();
        $plan = $service->plan();

        $this->assertSame(3, count($plan->operations));
        $this->assertInstanceOf(InMemorySyncStateStore::class, $fake->store());
    }

    public function test_definitions_is_a_stable_singleton(): void
    {
        $fake = $this->fake();

        $this->assertSame($fake->definitions(), $fake->definitions());
        $this->assertInstanceOf(DefinitionsService::class, $fake->definitions());
    }

    public function test_ready_presets_the_ready_manifest(): void
    {
        $fake = $this->fake()->ready();

        $ready = $fake->store()->readyManifest(self::C);

        $this->assertNotNull($ready);
        $this->assertSame($fake->definitions()->manifest()->hash, $ready->manifestHash);

        // The readiness gate is open.
        $fake->definitions()->requireSynchronized();
    }

    public function test_without_ready_the_gate_fails_closed(): void
    {
        $fake = $this->fake();

        $this->expectException(DefinitionsNotSynchronizedException::class);

        $fake->definitions()->requireSynchronized();
    }

    public function test_ready_then_definitions_change_fails_closed(): void
    {
        $fake = $this->fake()->ready();

        $path = $fake->client()->config()->definitionsPath;

        $this->assertIsString($path);
        file_put_contents($path.'/agents/orchestrator.md', $this->agentMarkdown('Changed.', "format: 1\nname: orchestrator\nmode: primary\n"));

        $this->expectException(DefinitionsNotSynchronizedException::class);

        $fake->definitions()->requireSynchronized();
    }

    public function test_scripted_reader_feeds_the_plan(): void
    {
        // The remote already owns a tool named like the local one: with
        // adoption off the planner reports a blocking unmanaged collision.
        $reader = new FakeRemoteReader(
            tools: [$this->remoteTool('query_db')],
            agents: [],
            teams: [],
        );
        $fake = $this->fake($reader);

        $plan = $fake->definitions()->plan();

        $this->assertNotSame([], $plan->blocking());
    }

    public function test_apply_calls_the_scripted_writer(): void
    {
        $writer = new FakeRemoteWriter;
        $fake = $this->fake(reader: null, writer: $writer);

        $report = $fake->definitions()->apply();

        $this->assertSame(SyncReportStatus::Succeeded, $report->status);
        $this->assertNotSame([], $writer->calls);
        $this->assertTrue($report->manifestReady);
    }

    public function test_apply_failure_is_scriptable(): void
    {
        $writer = new FakeRemoteWriter;
        // A validation rejection (ApiException) is a proven failure; a
        // TransportException would be ambiguous and block instead.
        $writer->scriptFailure('createAgent:orchestrator', FakeRemoteWriter::validationError('The payload is invalid.'));
        $fake = $this->fake(reader: null, writer: $writer);

        $report = $fake->definitions()->apply();

        $this->assertSame(SyncReportStatus::Failed, $report->status);
        $this->assertFalse($report->manifestReady);
    }

    public function test_default_reader_and_writer_are_client_backed(): void
    {
        $fake = EnvaiFake::make($this->client());

        $this->assertInstanceOf(ClientRemoteReader::class, $fake->reader());
        $this->assertInstanceOf(ClientRemoteWriter::class, $fake->writer());
    }

    public function test_scripted_reader_and_writer_are_used(): void
    {
        $reader = new FakeRemoteReader;
        $writer = new FakeRemoteWriter;
        $fake = $this->fake($reader, $writer);

        $this->assertSame($reader, $fake->reader());
        $this->assertSame($writer, $fake->writer());
    }

    public function test_connection_defaults_to_the_client_config(): void
    {
        $client = $this->client('staging');
        $fake = EnvaiFake::make($client)->ready();

        // The fake scopes the ready manifest to the client's configured
        // connection name.
        $this->assertNotNull($fake->store()->readyManifest('staging'));
        $this->assertNull($fake->store()->readyManifest(self::C));
    }

    public function test_client_accessor(): void
    {
        $client = $this->client();
        $fake = EnvaiFake::make($client);

        $this->assertSame($client, $fake->client());
    }

    public function test_swap_rebinds_the_container_bindings(): void
    {
        $fake = $this->fake()->ready();
        $container = new ContainerSpy;

        $fake->swap($container);

        $this->assertSame($fake->definitions(), $container->instances[DefinitionsService::class]);
        $this->assertSame($fake->definitions(), $container->instances['envai.definitions']);
        $this->assertSame($fake->client(), $container->instances['envai.client']);
        $this->assertSame($fake->client(), $container->instances['envai']);
        $this->assertSame($fake->client(), $container->instances['envai.client.'.self::C]);
    }

    public function test_swap_is_a_noop_without_an_instance_method(): void
    {
        $fake = $this->fake();

        $fake->swap(new \stdClass);

        $this->addToAssertionCount(1);
    }

    public function test_store_accessor_exposes_the_in_memory_store(): void
    {
        $fake = $this->fake();

        $this->assertInstanceOf(InMemorySyncStateStore::class, $fake->store());
        $this->assertSame($fake->store(), $fake->store());
    }

    private function fake(?FakeRemoteReader $reader = null, ?FakeRemoteWriter $writer = null): EnvaiFake
    {
        // Always inject the scriptable fakes: the default client-backed
        // reader/writer would perform real HTTP calls in the tests.
        return EnvaiFake::make(
            $this->client(),
            $reader ?? new FakeRemoteReader,
            $writer ?? new FakeRemoteWriter,
        );
    }

    private function client(string $connection = self::C): EnvaiClient
    {
        return new EnvaiClient(new EnvaiConfig(
            baseUrl: 'https://api.example.test',
            apiKey: new SensitiveString('test-key'),
            connectionName: $connection,
            definitionsPath: $this->layout(),
        ));
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
}

/**
 * Minimal instance()-exposing fake container used to prove that swap()
 * works with any container-like object, including Illuminate's.
 */
final class ContainerSpy
{
    /**
     * @var array<string, mixed>
     */
    public array $instances = [];

    public function instance(string $abstract, mixed $instance): void
    {
        $this->instances[$abstract] = $instance;
    }
}
