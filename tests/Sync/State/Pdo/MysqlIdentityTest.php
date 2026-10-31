<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\State\Pdo;

use Digitalygo\Envai\Integration\Laravel\LaravelDatabaseSyncStateStore;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\Pdo\PdoSyncStateStore;
use Digitalygo\Envai\Sync\State\ResourceType;
use Illuminate\Container\Container;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\TestCase;

final class MysqlIdentityTest extends TestCase
{
    public function test_published_laravel_migration_creates_exact_mysql_identity_columns(): void
    {
        if (! class_exists(MySqlConnection::class) || ! getenv('ENVAI_TEST_MYSQL_DSN')) {
            self::markTestSkipped('Actual Illuminate and disposable MySQL required.');
        }
        $pdo = new \PDO(getenv('ENVAI_TEST_MYSQL_DSN'), getenv('ENVAI_TEST_MYSQL_USER') ?: 'test', getenv('ENVAI_TEST_MYSQL_PASSWORD') ?: 'test');
        $prefix = 'migration_'.bin2hex(random_bytes(5)).'_';
        $connection = new MySqlConnection($pdo, 'test', $prefix, ['driver' => 'mysql']);
        $app = new Container;
        $app->instance('db.schema', $connection->getSchemaBuilder());
        Schema::clearResolvedInstances();
        Schema::setFacadeApplication($app);
        $migration = require dirname(__DIR__, 4).'/database/migrations/2026_08_28_000000_create_envai_sync_tables.php';
        try {
            $migration->up();
            foreach (['resources' => ['connection', 'resource_type', 'local_key'], 'manifests' => ['connection']] as $suffix => $columns) {
                foreach ($columns as $column) {
                    $query = $pdo->prepare('SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
                    $query->execute([$prefix.'envai_sync_'.$suffix, $column]);
                    self::assertSame('varbinary', $query->fetchColumn());
                }
            }
        } finally {
            $migration->down();
            Schema::clearResolvedInstances();
            Schema::setFacadeApplication(null);
        }
    }

    public function test_case_accent_and_trailing_space_scopes_keys_and_locks_are_distinct(): void
    {
        $dsn = getenv('ENVAI_TEST_MYSQL_DSN');
        if (! $dsn) {
            self::markTestSkipped('Disposable MySQL not configured.');
        }
        $pdo = new \PDO($dsn, getenv('ENVAI_TEST_MYSQL_USER') ?: 'test', getenv('ENVAI_TEST_MYSQL_PASSWORD') ?: 'test');
        $prefix = 'parity_'.bin2hex(random_bytes(5)).'_';
        try {
            $store = new PdoSyncStateStore($pdo, tablePrefix: $prefix);
            $store->ensureSchema();
            $stores = [$store];
            if (class_exists(MySqlConnection::class)) {
                $stores[] = new LaravelDatabaseSyncStateStore(new MySqlConnection($pdo, 'test'), tablePrefix: $prefix);
            }
            foreach ($stores as $index => $state) {
                $keys = ['Account', 'account', 'áccount', 'account '];
                $locks = [];
                foreach ($keys as $i => $scope) {
                    $scope = $index.$scope;
                    $locks[] = $state->acquireLock($scope);
                    $state->markManifestReady($scope, 'manifest-'.$i);
                    foreach ($keys as $j => $key) {
                        $state->saveResource(new ManagedResourceState($scope, ResourceType::Tool, $key, remoteId: 'resource-'.$i.'-'.$j, metadata: ['pending_action' => 'create', 'pending_payload' => ['name' => $key]]));
                    }
                }
                foreach ($keys as $i => $scope) {
                    $scope = $index.$scope;
                    self::assertSame('manifest-'.$i, $state->readyManifest($scope)->manifestHash);
                    foreach ($keys as $j => $key) {
                        self::assertSame('resource-'.$i.'-'.$j, $state->getResource($scope, ResourceType::Tool, $key)->remoteId);
                        self::assertSame(['pending_action' => 'create', 'pending_payload' => ['name' => $key]], $state->getResource($scope, ResourceType::Tool, $key)->metadata);
                    }
                }
                foreach ($locks as $lock) {
                    $lock->release();
                }
                foreach (['scope', 'key'] as $oversize) {
                    try {
                        $state->saveResource(new ManagedResourceState($oversize === 'scope' ? str_repeat('a', 765) : 'safe', ResourceType::Tool, $oversize === 'key' ? str_repeat('a', 765) : 'safe'));
                        self::fail('Oversized identity could be truncated.');
                    } catch (\InvalidArgumentException $error) {
                        self::assertStringContainsString('truncation is forbidden', $error->getMessage());
                    }
                }
            }
            $pdo->exec('TRUNCATE TABLE '.$prefix.'resources');
            $pdo->exec('ALTER TABLE '.$prefix.'resources MODIFY connection VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL');
            $unsafe = new PdoSyncStateStore($pdo, tablePrefix: $prefix);
            try {
                $unsafe->ensureSchema();
                self::fail('Unsafe existing schema accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('explicitly migrate', $error->getMessage());
            }
            if (class_exists(MySqlConnection::class)) {
                $unsafeLaravel = new LaravelDatabaseSyncStateStore(new MySqlConnection($pdo, 'test'), tablePrefix: $prefix);
                try {
                    iterator_to_array($unsafeLaravel->resources('Account'));
                    self::fail('Unsafe Laravel schema accepted.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('explicitly migrate', $error->getMessage());
                }
            }
        } finally {
            foreach (['resources', 'manifests', 'locks'] as $suffix) {
                $pdo->exec('DROP TABLE IF EXISTS '.$prefix.$suffix);
            }
        }
    }
}
