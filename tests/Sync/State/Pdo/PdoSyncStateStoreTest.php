<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\State\Pdo;

use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\Pdo\PdoSyncStateStore;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Tests\Sync\SyncTestCase;

/**
 * PDO-store-specific tests on SQLite (project document section 29.5):
 * the upsert conflict path, the stale lock takeover, lazy schema
 * creation, the schema guard, manifest metadata and the platform guard
 * for the MySQL/PostgreSQL contract suites.
 */
final class PdoSyncStateStoreTest extends SyncTestCase
{
    private ?\PDO $pdo = null;

    private ?string $sqliteFile = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sqliteFile = sys_get_temp_dir().'/atp-pdo-store-'.bin2hex(random_bytes(8)).'.sqlite';
        $this->pdo = new \PDO('sqlite:'.$this->sqliteFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
    }

    protected function tearDown(): void
    {
        if ($this->sqliteFile !== null) {
            @unlink($this->sqliteFile);
            @unlink($this->sqliteFile.'-wal');
            @unlink($this->sqliteFile.'-shm');
        }

        $this->pdo = null;

        parent::tearDown();
    }

    public function test_upsert_conflict_path_updates_in_place(): void
    {
        $now = new \DateTimeImmutable('2026-08-28T10:00:00+00:00');
        $holder = $this->clockHolder($now);
        $store = $this->store(nowProvider: fn (): \DateTimeImmutable => $holder->now);
        $store->ensureSchema();

        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'query_db', remoteId: 'twl_1', status: ManagedResourceStatus::Applied));
        $holder->now = $holder->now->modify('+1 hour');
        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'query_db', remoteId: 'twl_2', status: ManagedResourceStatus::Drifted));

        $this->assertSame(1, $this->countRows('envai_sync_resources'));
        $this->assertSame('twl_2', $store->getResource('production', ResourceType::Tool, 'query_db')?->remoteId);
        $this->assertSame(ManagedResourceStatus::Drifted, $store->getResource('production', ResourceType::Tool, 'query_db')?->status);
        $this->assertSame('2026-08-28T10:00:00.000000+00:00', $this->columnValue('envai_sync_resources', 'created_at'));
        $this->assertSame('2026-08-28T11:00:00.000000+00:00', $this->columnValue('envai_sync_resources', 'updated_at'));
    }

    public function test_stale_lock_takeover(): void
    {
        $storeA = $this->store(lockTimeoutSeconds: 0.3, autoMigrate: true);
        $lockA = $storeA->acquireLock('production');

        // Make the lock row stale, as if the previous holder crashed.
        $this->pdo->exec("UPDATE envai_sync_locks SET acquired_at = '2026-01-01T00:00:00.000000+00:00' WHERE connection = 'production'");

        $storeB = $this->store(lockTimeoutSeconds: 0.3, autoMigrate: true);
        $lockB = $storeB->acquireLock('production');

        $this->assertFalse($lockB->isReleased());

        // The previous holder's release must not undo the takeover.
        $lockA->release();

        $this->assertFalse($lockB->isReleased());
        $this->assertSame(1, $this->countRows('envai_sync_locks'));

        $lockB->release();

        $this->assertTrue($lockB->isReleased());
        $this->assertSame(0, $this->countRows('envai_sync_locks'));
    }

    public function test_auto_migrate_creates_schema_lazily(): void
    {
        $store = $this->store(autoMigrate: true);

        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a', remoteId: 'twl_1'));

        $this->assertSame('twl_1', $store->getResource('production', ResourceType::Tool, 'a')?->remoteId);
        $this->assertSame(1, $this->countRows('envai_sync_resources'));
    }

    public function test_operations_without_schema_throw_when_auto_migrate_is_false(): void
    {
        $store = $this->store();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ensureSchema');

        $store->getResource('production', ResourceType::Tool, 'a');
    }

    public function test_ensure_schema_is_idempotent(): void
    {
        $store = $this->store();

        $store->ensureSchema();
        $store->ensureSchema();
        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a'));

        $this->assertSame(1, $this->countRows('envai_sync_resources'));
    }

    public function test_ready_manifest_metadata_roundtrip(): void
    {
        $store = $this->store(autoMigrate: true);

        $store->markManifestReady('production', 'sha256:1', ['base_url' => 'https://api.envai.example']);

        $ready = $store->readyManifest('production');

        $this->assertSame('sha256:1', $ready?->manifestHash);
        $this->assertSame(['base_url' => 'https://api.envai.example'], $ready?->metadata);

        $reopened = $this->store(autoMigrate: true);

        $this->assertSame(['base_url' => 'https://api.envai.example'], $reopened->readyManifest('production')?->metadata);
    }

    public function test_remote_marker_list_roundtrip(): void
    {
        $store = $this->store(autoMigrate: true);

        $store->saveResource(new ManagedResourceState('production', ResourceType::Tool, 'a', remoteMarker: ['tag', 42]));

        $this->assertSame(['tag', 42], $store->getResource('production', ResourceType::Tool, 'a')?->remoteMarker);
    }

    public function test_mysql_contract_suite_requires_env_dsn(): void
    {
        $dsn = getenv('ENVAI_TEST_MYSQL_DSN');

        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('ENVAI_TEST_MYSQL_DSN is not set; PdoMysqlSyncStateStoreContractTest is skipped locally and runs in CI.');
        }

        $this->assertNotSame('', $dsn);
    }

    public function test_pgsql_contract_suite_requires_env_dsn(): void
    {
        $dsn = getenv('ENVAI_TEST_PGSQL_DSN');

        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('ENVAI_TEST_PGSQL_DSN is not set; PdoPgsqlSyncStateStoreContractTest is skipped locally and runs in CI.');
        }

        $this->assertNotSame('', $dsn);
    }

    private function store(float $lockTimeoutSeconds = 10.0, bool $autoMigrate = false, ?\Closure $nowProvider = null): PdoSyncStateStore
    {
        return new PdoSyncStateStore($this->pdo, 'envai_sync_', lockTimeoutSeconds: $lockTimeoutSeconds, autoMigrate: $autoMigrate, nowProvider: $nowProvider);
    }

    private function countRows(string $table): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM '.$table);
        $this->assertNotFalse($statement);
        $count = $statement->fetchColumn();
        $this->assertIsNumeric($count);

        return (int) $count;
    }

    private function columnValue(string $table, string $column): mixed
    {
        $statement = $this->pdo->query('SELECT '.$column.' FROM '.$table);
        $this->assertNotFalse($statement);

        return $statement->fetchColumn();
    }

    /**
     * @return object{now: \DateTimeImmutable}
     */
    private function clockHolder(\DateTimeImmutable $now): object
    {
        return new class($now)
        {
            public \DateTimeImmutable $now;

            public function __construct(\DateTimeImmutable $now)
            {
                $this->now = $now;
            }
        };
    }
}
