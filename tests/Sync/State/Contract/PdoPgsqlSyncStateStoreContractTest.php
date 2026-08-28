<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\State\Contract;

/**
 * Runs the full SyncStateStore contract suite against PdoSyncStateStore
 * on PostgreSQL (project document section 29.5). The suite only runs
 * when ENVAI_TEST_PGSQL_DSN is set (CI); locally every test is skipped.
 * ENVAI_TEST_PGSQL_USER and ENVAI_TEST_PGSQL_PASSWORD are optional
 * credentials. Tables are created under a per-test prefix and dropped in
 * tearDown.
 */
final class PdoPgsqlSyncStateStoreContractTest extends PdoSyncStateStoreContractTestCase
{
    protected function setUpConnection(): void
    {
        $dsn = getenv('ENVAI_TEST_PGSQL_DSN');

        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('ENVAI_TEST_PGSQL_DSN is not set; the PostgreSQL contract suite runs only when a PostgreSQL server is configured (CI).');
        }

        $user = getenv('ENVAI_TEST_PGSQL_USER');
        $password = getenv('ENVAI_TEST_PGSQL_PASSWORD');

        $this->pdo = new \PDO(
            $dsn,
            $user === false || $user === '' ? null : $user,
            $password === false || $password === '' ? null : $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $this->tablePrefix = 'atp_contract_'.bin2hex(random_bytes(4)).'_';
    }
}
