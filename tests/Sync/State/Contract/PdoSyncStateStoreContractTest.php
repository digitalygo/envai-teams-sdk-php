<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\State\Contract;

/**
 * Runs the full SyncStateStore contract suite against PdoSyncStateStore
 * on SQLite. Uses a temporary database
 * file per test method, so the suite always runs locally and the
 * durability test can re-instantiate stores over the same file.
 */
final class PdoSyncStateStoreContractTest extends PdoSyncStateStoreContractTestCase
{
    private ?string $sqliteFile = null;

    protected function setUpConnection(): void
    {
        $this->sqliteFile = sys_get_temp_dir().'/atp-pdo-state-'.bin2hex(random_bytes(8)).'.sqlite';
        $this->pdo = new \PDO('sqlite:'.$this->sqliteFile, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->tablePrefix = 'envai_sync_';
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        if ($this->sqliteFile !== null) {
            @unlink($this->sqliteFile);
            @unlink($this->sqliteFile.'-wal');
            @unlink($this->sqliteFile.'-shm');
        }

        $this->sqliteFile = null;
    }
}
