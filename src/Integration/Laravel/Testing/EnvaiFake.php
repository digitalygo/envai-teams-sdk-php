<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel\Testing;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Definitions\Compiler\DefinitionCompiler;
use Digitalygo\Envai\Sync\Client\ClientRemoteReader;
use Digitalygo\Envai\Sync\Client\ClientRemoteWriter;
use Digitalygo\Envai\Sync\DefinitionsService;
use Digitalygo\Envai\Sync\Planner\RemoteReader;
use Digitalygo\Envai\Sync\Reconciler\RemoteWriter;
use Digitalygo\Envai\Sync\State\InMemorySyncStateStore;

/**
 * Test double factory for application tests of the Laravel integration.
 *
 * Wraps a real EnvaiClient and wires a DefinitionsService over an
 * in-memory sync state store (no database involved) plus scriptable
 * remote reader/writer fakes. The application code under test resolves
 * the definitions service from the container; swap() replaces the
 * provider's bindings so that resolution yields the fake wiring.
 *
 * Usage:
 *
 * ```php
 * $fake = EnvaiFake::make($client, new FakeRemoteReader, new FakeRemoteWriter);
 * $fake->ready();          // preset the ready manifest from the compiled definitions
 * $fake->swap($this->app); // rebind the container
 * ```
 *
 * ready() presets the last ready manifest with the hash of the currently
 * compiled local definitions, so requireSynchronized() (and anything
 * gated on it) passes immediately. When ready() is never called, the
 * connection is treated as never synchronized and the readiness gate
 * fails closed, which is the right default for tests that want to
 * exercise the sync itself.
 *
 * The default reader/writer are backed by the client's own services
 * (ClientRemoteReader/ClientRemoteWriter over the real HTTP transport);
 * pass fakes implementing RemoteReader/RemoteWriter to script the remote
 * side without any HTTP. swap() works with any container exposing an
 * instance() method (Illuminate's Container among them) and is a no-op
 * for anything else.
 */
final class EnvaiFake
{
    private ?DefinitionsService $definitions = null;

    private function __construct(
        private readonly EnvaiClient $client,
        private readonly InMemorySyncStateStore $store,
        private readonly RemoteReader $reader,
        private readonly RemoteWriter $writer,
        private readonly string $connection,
        private readonly string $definitionsPath,
    ) {}

    /**
     * Builds the fake over an existing client. The connection defaults
     * to the client's configured connection name; the definitions path
     * defaults to the client's configured path (or `envai`).
     */
    public static function make(
        EnvaiClient $client,
        ?RemoteReader $reader = null,
        ?RemoteWriter $writer = null,
        ?string $connection = null,
    ): self {
        $config = $client->config();

        return new self(
            client: $client,
            store: new InMemorySyncStateStore,
            reader: $reader ?? new ClientRemoteReader($client->tools(), $client->agents(), $client->teams(), $client->models()),
            writer: $writer ?? new ClientRemoteWriter($client->tools(), $client->agents(), $client->teams()),
            connection: $connection ?? $config->connectionName,
            definitionsPath: $config->definitionsPath ?? 'envai',
        );
    }

    /**
     * Presets the ready manifest from the currently compiled local
     * definitions, opening the readiness gate. Fluent.
     */
    public function ready(): self
    {
        $hash = (new DefinitionCompiler)->compile($this->definitionsPath)->manifest->hash;

        $this->store->markManifestReady($this->connection, $hash);

        return $this;
    }

    /**
     * The definitions service wired over the in-memory store and the
     * scripted remote fakes. The instance is stable: every call returns
     * the same service.
     */
    public function definitions(): DefinitionsService
    {
        return $this->definitions ??= new DefinitionsService(
            compiler: new DefinitionCompiler,
            store: $this->store,
            remoteReader: $this->reader,
            remoteWriter: $this->writer,
            connection: $this->connection,
            definitionsPath: $this->definitionsPath,
        );
    }

    /**
     * Replaces the container bindings of the Laravel integration with
     * the fake wiring: the default DefinitionsService, its string alias
     * and the client aliases for the faked connection. Any container
     * exposing instance() is supported; containers without it are left
     * untouched (the fake then only works through direct access).
     */
    public function swap(object $container): void
    {
        if (! method_exists($container, 'instance')) {
            return;
        }

        $container->instance(DefinitionsService::class, $this->definitions());
        $container->instance('envai.definitions', $this->definitions());
        $container->instance('envai.client', $this->client);
        $container->instance('envai.client.'.$this->connection, $this->client);
        $container->instance('envai', $this->client);
    }

    public function store(): InMemorySyncStateStore
    {
        return $this->store;
    }

    public function reader(): RemoteReader
    {
        return $this->reader;
    }

    public function writer(): RemoteWriter
    {
        return $this->writer;
    }

    public function client(): EnvaiClient
    {
        return $this->client;
    }
}
