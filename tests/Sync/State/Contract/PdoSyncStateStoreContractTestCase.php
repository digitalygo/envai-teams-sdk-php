<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\State\Contract;

use Digitalygo\Envai\Sync\State\Pdo\PdoSyncStateStore;
use Digitalygo\Envai\Sync\State\SyncStateStore;

/**
 * Shared PDO fixture for the SyncStateStore contract suite: connects a
 * database, builds stores over a per-test table prefix, creates the
 * schema and drops the tables again in tearDown. Concrete classes only
 * implement setUpConnection() to choose the platform (SQLite always
 * runs; MySQL and PostgreSQL skip when their env DSN is unset).
 */
abstract class PdoSyncStateStoreContractTestCase extends SyncStateStoreContractTestCase
{
    protected ?\PDO $pdo = null;

    protected string $tablePrefix = 'envai_sync_';

    /**
     * Connects the fixture database and sets $this->pdo and
     * $this->tablePrefix. Implementations call markTestSkipped() here
     * when their platform is not available in the current environment.
     */
    abstract protected function setUpConnection(): void;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConnection();
    }

    protected function makeStore(float $lockTimeoutSeconds = 5.0): SyncStateStore
    {
        $store = new PdoSyncStateStore($this->pdo, $this->tablePrefix, lockTimeoutSeconds: $lockTimeoutSeconds, autoMigrate: false);
        $store->ensureSchema();

        return $store;
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            foreach (['manifests', 'resources', 'locks'] as $suffix) {
                try {
                    $this->pdo->exec('DROP TABLE IF EXISTS '.$this->tablePrefix.$suffix);
                } catch (\PDOException) {
                    // Best-effort cleanup.
                }
            }
        }

        $this->pdo = null;

        parent::tearDown();
    }
}
