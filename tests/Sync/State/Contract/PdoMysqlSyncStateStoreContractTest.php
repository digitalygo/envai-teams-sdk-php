<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\State\Contract;

/**
 * Runs the full SyncStateStore contract suite against PdoSyncStateStore
 * on MySQL or MariaDB. The suite only
 * runs when ENVAI_TEST_MYSQL_DSN is set (CI); locally every test is
 * skipped. ENVAI_TEST_MYSQL_USER and ENVAI_TEST_MYSQL_PASSWORD are
 * optional credentials. Tables are created under a per-test prefix and
 * dropped in tearDown.
 */
final class PdoMysqlSyncStateStoreContractTest extends PdoSyncStateStoreContractTestCase
{
    protected function setUpConnection(): void
    {
        $dsn = getenv('ENVAI_TEST_MYSQL_DSN');

        if ($dsn === false || $dsn === '') {
            $this->markTestSkipped('ENVAI_TEST_MYSQL_DSN is not set; the MySQL contract suite runs only when a MySQL or MariaDB server is configured (CI).');
        }

        $user = getenv('ENVAI_TEST_MYSQL_USER');
        $password = getenv('ENVAI_TEST_MYSQL_PASSWORD');

        $this->pdo = new \PDO(
            $dsn,
            $user === false || $user === '' ? null : $user,
            $password === false || $password === '' ? null : $password,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        $this->tablePrefix = 'atp_contract_'.bin2hex(random_bytes(4)).'_';
    }
}
