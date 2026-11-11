<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Digitalygo\Envai\Integration\Laravel\LaravelDatabaseSyncStateStore;
use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ReadyManifest;
use Digitalygo\Envai\Sync\State\ResourceType;
use Illuminate\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;

/**
 * Store-specific tests of LaravelDatabaseSyncStateStore that go beyond
 * the contract suite: lazy locks-table creation, stale lock takeover
 * with the owner-token release semantics, nowProvider-driven timestamps,
 * upsert semantics and manifest metadata round-trips. Skipped cleanly
 * when illuminate/database is absent.
 */
final class LaravelDatabaseSyncStateStoreTest extends IntegrationTestCase
{
    private const PREFIX = 'envai_sync_';

    private ?ConnectionInterface $connection = null;

    protected function setUp(): void
    {
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

        $schema = $this->connection->getSchemaBuilder();

        $schema->create(self::PREFIX.'resources', function (Blueprint $table): void {
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

        $schema->create(self::PREFIX.'manifests', function (Blueprint $table): void {
            $table->string('connection', 191)->primary();
            $table->string('manifest_hash', 191);
            $table->string('synchronized_at', 64);
            $table->text('metadata');
        });
    }

    protected function tearDown(): void
    {
        if ($this->connection !== null) {
            $schema = $this->connection->getSchemaBuilder();

            foreach (['manifests', 'resources', 'locks'] as $suffix) {
                $table = self::PREFIX.$suffix;

                if ($schema->hasTable($table)) {
                    $schema->drop($table);
                }
            }
        }

        $this->connection = null;

        parent::tearDown();
    }

    public function test_locks_table_is_created_lazily(): void
    {
        $this->assertFalse($this->schemaHasTable(self::PREFIX.'locks'));

        $store = $this->store();
        $lock = $store->acquireLock('production');

        $this->assertTrue($this->schemaHasTable(self::PREFIX.'locks'));
        $this->assertFalse($lock->isReleased());

        $lock->release();

        $this->assertTrue($lock->isReleased());
    }

    public function test_ensure_locks_schema_is_idempotent(): void
    {
        $store = $this->store();

        $store->ensureLocksSchema();
        $store->ensureLocksSchema();

        $this->assertTrue($this->schemaHasTable(self::PREFIX.'locks'));
    }

    public function test_invalid_table_prefix_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new LaravelDatabaseSyncStateStore($this->connection, 'envai sync!');
    }

    public function test_acquired_at_uses_the_now_provider(): void
    {
        $fixed = new \DateTimeImmutable('2026-08-28T10:00:00.123456+02:00');
        $store = new LaravelDatabaseSyncStateStore($this->connection, self::PREFIX, nowProvider: fn (): \DateTimeImmutable => $fixed);

        $lock = $store->acquireLock('production');

        $this->assertSame(
            '2026-08-28T10:00:00.123456+02:00',
            $this->connection->table(self::PREFIX.'locks')->where('connection', 'production')->value('acquired_at'),
        );

        $lock->release();
    }

    public function test_now_provider_must_return_a_datetime(): void
    {
        $store = new LaravelDatabaseSyncStateStore($this->connection, self::PREFIX, nowProvider: fn (): mixed => 'nope');

        $this->expectException(\InvalidArgumentException::class);

        $store->acquireLock('production');
    }

    public function test_stale_lock_is_taken_over(): void
    {
        $first = new LaravelDatabaseSyncStateStore($this->connection, self::PREFIX, staleLockSeconds: 0.0);

        $lock = $first->acquireLock('production');

        $this->assertFalse($lock->isReleased());

        // A staleLockSeconds of 0 treats any held row as stale, as if the
        // holder crashed without releasing.
        $second = new LaravelDatabaseSyncStateStore($this->connection, self::PREFIX, staleLockSeconds: 0.0);

        $taken = $second->acquireLock('production');

        $this->assertFalse($taken->isReleased());

        // The previous owner's release cannot undo the takeover.
        $lock->release();

        $this->assertSame(
            1,
            $this->connection->table(self::PREFIX.'locks')->where('connection', 'production')->count(),
        );

        $taken->release();

        $this->assertSame(
            0,
            $this->connection->table(self::PREFIX.'locks')->where('connection', 'production')->count(),
        );
    }

    public function test_fresh_lock_is_not_taken_over_below_the_stale_threshold(): void
    {
        $now = new \DateTimeImmutable('2026-08-28T10:00:00+00:00');
        $first = new LaravelDatabaseSyncStateStore($this->connection, self::PREFIX, lockTimeoutSeconds: 10.0, staleLockSeconds: 3600.0, nowProvider: fn (): \DateTimeImmutable => $now);
        $first->acquireLock('production')->release();

        $lock = $first->acquireLock('production');

        $this->assertFalse($lock->isReleased());

        $lock->release();
    }

    public function test_acquiring_a_freshly_held_lock_times_out(): void
    {
        $now = new \DateTimeImmutable('2026-08-28T10:00:00+00:00');
        $holder = new LaravelDatabaseSyncStateStore($this->connection, self::PREFIX, lockTimeoutSeconds: 10.0, nowProvider: fn (): \DateTimeImmutable => $now);
        $lock = $holder->acquireLock('production');

        $this->assertFalse($lock->isReleased());

        $contender = new LaravelDatabaseSyncStateStore($this->connection, self::PREFIX, lockTimeoutSeconds: 0.2, nowProvider: fn (): \DateTimeImmutable => $now);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('could not acquire envai state lock');

        $contender->acquireLock('production');
    }

    public function test_save_resource_is_an_upsert(): void
    {
        $store = $this->store();

        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a', remoteId: 'twl_1', status: ManagedResourceStatus::Missing));
        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a', remoteId: 'twl_2', status: ManagedResourceStatus::Applied));

        $this->assertSame('twl_2', $store->getResource('production', ResourceType::Tool, 'a')?->remoteId);
        $this->assertSame(ManagedResourceStatus::Applied, $store->getResource('production', ResourceType::Tool, 'a')?->status);
        $this->assertCount(1, iterator_to_array($store->resources('production'), false));
    }

    public function test_mark_manifest_ready_is_an_upsert_per_connection(): void
    {
        $store = $this->store();

        $store->markManifestReady('production', 'sha256:1');
        $store->markManifestReady('production', 'sha256:2');
        $store->markManifestReady('staging', 'sha256:1');

        $this->assertSame('sha256:2', $store->readyManifest('production')?->manifestHash);
        $this->assertSame('sha256:1', $store->readyManifest('staging')?->manifestHash);
    }

    public function test_manifest_metadata_roundtrips(): void
    {
        $store = $this->store();

        $store->markManifestReady('production', 'sha256:1', ['base_url' => 'https://api.example.test']);

        $ready = $store->readyManifest('production');

        $this->assertInstanceOf(ReadyManifest::class, $ready);
        $this->assertSame(['base_url' => 'https://api.example.test'], $ready->metadata);
    }

    public function test_synchronized_at_format_is_preserved(): void
    {
        $store = $this->store();
        $synchronizedAt = new \DateTimeImmutable('2026-08-28T10:00:00.123456+02:00');
        $store->saveResource(new ManagedResourceState('production', ResourceType::Agent, 'a', synchronizedAt: $synchronizedAt, status: ManagedResourceStatus::Applied));

        $this->assertSame(
            '2026-08-28T10:00:00.123456+02:00',
            $store->getResource('production', ResourceType::Agent, 'a')?->synchronizedAt?->format('Y-m-d\TH:i:s.uP'),
        );
    }

    public function test_nested_payload_roundtrip_through_upsert(): void
    {
        $store = $this->store();
        $payload = new JsonObject([
            'name' => 'query_db',
            'config' => new JsonObject([]),
            'tags' => new JsonArray(['a', 'b']),
        ]);
        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'nested', lastAppliedPayload: $payload, status: ManagedResourceStatus::Applied));

        $read = $store->getResource('production', ResourceType::Tool, 'nested')?->lastAppliedPayload;

        $this->assertInstanceOf(JsonObject::class, $read);
        $this->assertInstanceOf(JsonObject::class, $read->get('config'));
        $this->assertInstanceOf(JsonArray::class, $read->get('tags'));
        $this->assertSame(['a', 'b'], $read->get('tags')->toArray());
    }

    public function test_missing_state_tables_throw_a_query_exception(): void
    {
        $schema = $this->connection->getSchemaBuilder();
        $schema->drop(self::PREFIX.'resources');

        $store = $this->store();

        $this->expectException(QueryException::class);

        // The generator only executes on iteration, which is where the
        // missing table surfaces.
        iterator_to_array($store->resources('production'), false);
    }

    private function store(): LaravelDatabaseSyncStateStore
    {
        return new LaravelDatabaseSyncStateStore($this->connection, self::PREFIX);
    }

    private function schemaHasTable(string $table): bool
    {
        return $this->connection->getSchemaBuilder()->hasTable($table);
    }
}
