<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Digitalygo\Envai\Integration\Laravel\Compatibility;
use Digitalygo\Envai\Integration\Laravel\LaravelDatabaseSyncStateStore;
use Digitalygo\Envai\Sync\State\SyncStateStore;
use Digitalygo\Envai\Tests\Sync\State\Contract\SyncStateStoreContractTestCase;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\Schema\Blueprint;

/**
 * Runs the full SyncStateStore contract suite (section 29.5) against the
 * Laravel database store over a real Illuminate Connection on PDO
 * SQLite. The resources and manifests tables are created in the fixture
 * with the same columns as the published migration; the locks table is
 * ensured lazily by the store itself. Skipped cleanly when
 * illuminate/database is absent.
 */
final class LaravelDatabaseSyncStateStoreContractTest extends SyncStateStoreContractTestCase
{
    private ?ConnectionInterface $connection = null;

    private string $tablePrefix = 'envai_sync_';

    protected function setUp(): void
    {
        if (! Compatibility::illuminateDatabaseAvailable()) {
            $this->markTestSkipped('illuminate/database is not installed; run the Laravel integration tests in an environment with it.');
        }

        parent::setUp();

        // Build the connection through the factory: the driver-specific
        // connection class wires the schema grammar (a raw `new
        // Connection` never receives one since Illuminate 13).
        $factory = new ConnectionFactory(new Container);
        $connection = $factory->make(['driver' => 'sqlite', 'database' => ':memory:'], 'default');

        if (! $connection instanceof ConnectionInterface) {
            $this->fail('The factory did not return an Illuminate ConnectionInterface.');
        }

        $this->connection = $connection;
        $this->tablePrefix = 'envai_sync_';

        $schema = $this->connection->getSchemaBuilder();

        $schema->create($this->tablePrefix.'resources', function (Blueprint $table): void {
            $table->string('connection', 191);
            $table->string('resource_type', 64);
            $table->string('local_key', 191);
            $table->string('remote_id', 191)->nullable();
            $table->string('remote_name', 191)->nullable();
            $table->string('desired_hash', 191)->nullable();
            $table->text('last_applied_payload')->nullable();
            $table->text('remote_marker')->nullable();
            $table->text('state_metadata')->nullable();
            $table->string('status', 32);
            $table->string('synchronized_at', 64)->nullable();
            $table->string('created_at', 64);
            $table->string('updated_at', 64);
            $table->primary(['connection', 'resource_type', 'local_key']);
        });

        $schema->create($this->tablePrefix.'manifests', function (Blueprint $table): void {
            $table->string('connection', 191)->primary();
            $table->string('manifest_hash', 191);
            $table->string('synchronized_at', 64);
            $table->text('metadata');
        });
    }

    protected function makeStore(float $lockTimeoutSeconds = 5.0): SyncStateStore
    {
        return new LaravelDatabaseSyncStateStore(
            $this->connection,
            $this->tablePrefix,
            lockTimeoutSeconds: $lockTimeoutSeconds,
        );
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $schema = $this->connection->getSchemaBuilder();

            foreach (['manifests', 'resources', 'locks'] as $suffix) {
                $table = $this->tablePrefix.$suffix;

                if ($schema->hasTable($table)) {
                    $schema->drop($table);
                }
            }
        }

        $this->connection = null;

        parent::tearDown();
    }
}
