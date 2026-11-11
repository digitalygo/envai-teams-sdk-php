<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\State;

use Digitalygo\Envai\Tools\State\PostgresClientToolCallStore;
use Digitalygo\Envai\Tools\State\ToolCallIdentity;
use Digitalygo\Envai\Tools\State\ToolCallStatus;
use PDO;
use PHPUnit\Framework\TestCase;

final class PostgresClientToolCallStoreTest extends TestCase
{
    private PDO $db;

    private string $prefix;

    protected function setUp(): void
    {
        $this->db = self::connection();
        $this->prefix = 'tool_test_'.bin2hex(random_bytes(6)).'_';
        foreach (PostgresClientToolCallStore::schemaStatements($this->prefix) as $statement) {
            $this->db->exec($statement);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->db, $this->prefix)) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            $this->db->exec('DROP TABLE '.$this->prefix.'calls');
            $this->db->exec('DROP TABLE '.$this->prefix.'barriers');
        }
    }

    public function test_claims_are_scoped_durable_and_resolved_state_cannot_be_downgraded(): void
    {
        $identity = new ToolCallIdentity('chat', 'call', 'echo', 'turn');
        $store = new PostgresClientToolCallStore($this->db, 'account-production', $this->prefix);
        self::assertSame(ToolCallStatus::Started, $store->begin($identity)->status);
        $other = new PostgresClientToolCallStore(self::connection(), 'account-production', $this->prefix);
        self::assertSame(ToolCallStatus::AlreadyRunning, $other->begin($identity)->status);
        $other->markAmbiguous($identity);
        self::assertSame(ToolCallStatus::AlreadyAmbiguous, $store->begin($identity)->status);
        $store->markResolved($identity);
        $other->markSubmitted($identity);
        $other->markAmbiguous($identity);
        self::assertSame(ToolCallStatus::AlreadyResolved, $store->begin($identity)->status);
        self::assertSame(ToolCallStatus::AlreadyResolved, $other->begin(new ToolCallIdentity('chat', 'call', 'echo'))->status);
        $isolated = new PostgresClientToolCallStore(self::connection(), 'different-account', $this->prefix);
        self::assertSame(ToolCallStatus::Started, $isolated->begin($identity)->status);
    }

    public function test_replay_barriers_persist_across_connections_and_are_scoped(): void
    {
        $store = new PostgresClientToolCallStore($this->db, 'account', $this->prefix);
        $other = new PostgresClientToolCallStore(self::connection(), 'account', $this->prefix);
        foreach (['call', 'turn', 'task'] as $kind) {
            self::assertFalse($other->isTerminal('chat', $kind, 'identity'));
            $store->markTerminal('chat', $kind, 'identity');
            $store->markTerminal('chat', $kind, 'identity');
            self::assertTrue($other->isTerminal('chat', $kind, 'identity'));
            self::assertFalse($other->isTerminal('other-chat', $kind, 'identity'));
        }
        $isolated = new PostgresClientToolCallStore(self::connection(), 'other-account', $this->prefix);
        self::assertFalse($isolated->isTerminal('chat', 'task', 'identity'));
    }

    public function test_corrupt_existing_state_is_not_reclaimed_or_modified(): void
    {
        $identity = new ToolCallIdentity('chat', 'call', 'echo');
        $store = new PostgresClientToolCallStore($this->db, 'account', $this->prefix);
        $store->begin($identity);
        $this->db->exec("UPDATE {$this->prefix}calls SET status = 'corrupt'");
        try {
            $store->begin($identity);
            self::fail('Corrupt state was accepted.');
        } catch (\UnexpectedValueException) {
            self::assertFalse($this->db->inTransaction());
            self::assertSame('corrupt', $this->db->query('SELECT status FROM '.$this->prefix.'calls')->fetchColumn());
        }
        $this->expectException(\UnexpectedValueException::class);
        $store->markResolved($identity);
    }

    public function test_existing_call_cannot_change_tool_identity(): void
    {
        $store = new PostgresClientToolCallStore($this->db, 'account', $this->prefix);
        $store->begin(new ToolCallIdentity('chat', 'call', 'echo'));
        $this->expectException(\UnexpectedValueException::class);
        $store->begin(new ToolCallIdentity('chat', 'call', 'another_tool'));
    }

    public function test_store_does_not_commit_or_roll_back_a_caller_transaction(): void
    {
        $store = new PostgresClientToolCallStore($this->db, 'account', $this->prefix);
        $this->db->beginTransaction();
        try {
            $store->begin(new ToolCallIdentity('chat', 'call', 'echo'));
            self::fail('Nested caller transaction accepted.');
        } catch (\LogicException) {
            self::assertTrue($this->db->inTransaction());
        } finally {
            $this->db->rollBack();
        }
        self::assertSame(0, $this->db->query('SELECT COUNT(*) FROM '.$this->prefix.'calls')->fetchColumn());
    }

    public function test_missing_schema_is_not_automatically_created(): void
    {
        $missing = new PostgresClientToolCallStore($this->db, 'account', $this->prefix.'missing_');
        $this->expectException(\PDOException::class);
        $missing->begin(new ToolCallIdentity('chat', 'call', 'echo'));
    }

    public function test_missing_claim_cannot_be_transitioned(): void
    {
        $store = new PostgresClientToolCallStore($this->db, 'account', $this->prefix);
        $this->expectException(\LogicException::class);
        $store->markSubmitted(new ToolCallIdentity('chat', 'missing', 'echo'));
    }

    public function test_independent_processes_claim_exactly_once(): void
    {
        $script = tempnam(sys_get_temp_dir(), 'envai-tool-pg');
        self::assertIsString($script);
        file_put_contents($script, <<<'PHP'
<?php
require $argv[1];
$db = new PDO(getenv('ENVAI_TEST_PGSQL_DSN'), getenv('ENVAI_TEST_PGSQL_USER') ?: '', getenv('ENVAI_TEST_PGSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$store = new Digitalygo\Envai\Tools\State\PostgresClientToolCallStore($db, 'account', $argv[2]);
echo $store->begin(new Digitalygo\Envai\Tools\State\ToolCallIdentity('chat', 'same-call', 'echo'))->status->value;
PHP);
        $workers = [];
        try {
            for ($i = 0; $i < 6; $i++) {
                $pipes = [];
                $process = proc_open([PHP_BINARY, $script, dirname(__DIR__, 3).'/vendor/autoload.php', $this->prefix], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
                self::assertIsResource($process);
                $workers[] = [$process, $pipes];
            }
            $results = [];
            foreach ($workers as [$process, $pipes]) {
                $results[] = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                fclose($pipes[1]);
                fclose($pipes[2]);
                self::assertSame(0, proc_close($process), $errors);
            }
            self::assertSame(1, count(array_filter($results, static fn (string $value): bool => $value === ToolCallStatus::Started->value)));
            self::assertSame(5, count(array_filter($results, static fn (string $value): bool => $value === ToolCallStatus::AlreadyRunning->value)));
        } finally {
            unlink($script);
        }
    }

    private static function connection(): PDO
    {
        $dsn = getenv('ENVAI_TEST_PGSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('ENVAI_TEST_PGSQL_DSN is required for PostgreSQL tool-state tests.');
        }

        return new PDO($dsn, getenv('ENVAI_TEST_PGSQL_USER') ?: '', getenv('ENVAI_TEST_PGSQL_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
