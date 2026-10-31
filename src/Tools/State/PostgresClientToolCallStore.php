<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\State;

use PDO;

/** Shared, scoped tool claims. Apply schemaStatements() in an explicit migration. */
final readonly class PostgresClientToolCallStore implements ClientToolCallStore, ToolReplayBarrierStore
{
    private string $calls;

    private string $barriers;

    public function __construct(private PDO $db, private string $scope, string $tablePrefix = 'envai_client_tool_')
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql' || $db->getAttribute(PDO::ATTR_ERRMODE) !== PDO::ERRMODE_EXCEPTION) {
            throw new \InvalidArgumentException('A PostgreSQL PDO connection with exception error mode is required.');
        }
        self::nonempty($scope);
        self::prefix($tablePrefix);
        $this->calls = $tablePrefix.'calls';
        $this->barriers = $tablePrefix.'barriers';
    }

    /** @return list<string> */
    public static function schemaStatements(string $tablePrefix = 'envai_client_tool_'): array
    {
        self::prefix($tablePrefix);

        return [
            'CREATE TABLE '.$tablePrefix.'calls (scope text NOT NULL, chat_id text NOT NULL, call_id text NOT NULL, tool_name text NOT NULL, turn_id text NULL, status text NOT NULL, updated_at timestamptz NOT NULL DEFAULT clock_timestamp(), PRIMARY KEY (scope, chat_id, call_id))',
            'CREATE TABLE '.$tablePrefix.'barriers (scope text NOT NULL, chat_id text NOT NULL, kind text NOT NULL, identity text NOT NULL, PRIMARY KEY (scope, chat_id, kind, identity))',
        ];
    }

    public function begin(ToolCallIdentity $identity): BeginResult
    {
        self::identity($identity);

        return $this->transaction(function () use ($identity): BeginResult {
            $insert = $this->db->prepare('INSERT INTO '.$this->calls.' (scope, chat_id, call_id, tool_name, turn_id, status) VALUES (?, ?, ?, ?, ?, ?) ON CONFLICT (scope, chat_id, call_id) DO NOTHING');
            $insert->execute([$this->scope, $identity->chatId, $identity->callId, $identity->toolName, $identity->turnId, ClientToolCallStatus::Started->value]);
            $record = $this->read($identity);
            if ($insert->rowCount() === 1) {
                return new BeginResult(ToolCallStatus::Started);
            }

            return new BeginResult(match ($record->status) {
                ClientToolCallStatus::Started, ClientToolCallStatus::Submitted => ToolCallStatus::AlreadyRunning,
                ClientToolCallStatus::Resolved => ToolCallStatus::AlreadyResolved,
                ClientToolCallStatus::Ambiguous => ToolCallStatus::AlreadyAmbiguous,
            }, $record);
        });
    }

    public function markSubmitted(ToolCallIdentity $identity): void
    {
        $this->transition($identity, ClientToolCallStatus::Submitted);
    }

    public function markResolved(ToolCallIdentity $identity): void
    {
        $this->transition($identity, ClientToolCallStatus::Resolved);
    }

    public function markAmbiguous(ToolCallIdentity $identity): void
    {
        $this->transition($identity, ClientToolCallStatus::Ambiguous);
    }

    public function markTerminal(string $chatId, string $kind, string $id): void
    {
        self::barrier($chatId, $kind, $id);
        $this->transaction(function () use ($chatId, $kind, $id): void {
            $statement = $this->db->prepare('INSERT INTO '.$this->barriers.' (scope, chat_id, kind, identity) VALUES (?, ?, ?, ?) ON CONFLICT (scope, chat_id, kind, identity) DO NOTHING');
            $statement->execute([$this->scope, $chatId, $kind, $id]);
        });
    }

    public function isTerminal(string $chatId, string $kind, string $id): bool
    {
        self::barrier($chatId, $kind, $id);

        return $this->transaction(function () use ($chatId, $kind, $id): bool {
            $statement = $this->db->prepare('SELECT 1 FROM '.$this->barriers.' WHERE scope = ? AND chat_id = ? AND kind = ? AND identity = ?');
            $statement->execute([$this->scope, $chatId, $kind, $id]);

            return $statement->fetchColumn() !== false;
        });
    }

    private function transition(ToolCallIdentity $identity, ClientToolCallStatus $status): void
    {
        self::identity($identity);
        $this->transaction(function () use ($identity, $status): void {
            $previous = $this->read($identity);
            if ($previous->status === ClientToolCallStatus::Resolved || ($previous->status === ClientToolCallStatus::Ambiguous && $status === ClientToolCallStatus::Submitted)) {
                return;
            }
            $statement = $this->db->prepare('UPDATE '.$this->calls.' SET status = ?, updated_at = clock_timestamp() WHERE scope = ? AND chat_id = ? AND call_id = ?');
            $statement->execute([$status->value, $this->scope, $identity->chatId, $identity->callId]);
        });
    }

    private function read(ToolCallIdentity $identity): ToolCallRecord
    {
        $statement = $this->db->prepare('SELECT tool_name, turn_id, status, updated_at FROM '.$this->calls.' WHERE scope = ? AND chat_id = ? AND call_id = ? FOR UPDATE');
        $statement->execute([$this->scope, $identity->chatId, $identity->callId]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (! is_array($row)) {
            throw new \LogicException('Cannot transition a missing tool claim.');
        }
        if (! is_string($row['tool_name']) || $row['tool_name'] !== $identity->toolName || ($row['turn_id'] !== null && ! is_string($row['turn_id'])) || ! is_string($row['status']) || ! is_string($row['updated_at'])) {
            throw new \UnexpectedValueException('Persisted tool claim has an invalid or conflicting identity.');
        }
        $status = ClientToolCallStatus::tryFrom($row['status']);
        if ($status === null) {
            throw new \UnexpectedValueException('Persisted tool claim has an invalid status.');
        }

        return new ToolCallRecord(new ToolCallIdentity($identity->chatId, $identity->callId, $identity->toolName, $row['turn_id']), $status, new \DateTimeImmutable($row['updated_at']));
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $operation
     * @return T
     */
    private function transaction(\Closure $operation): mixed
    {
        if ($this->db->inTransaction()) {
            throw new \LogicException('Tool state requires its own idle database connection; caller transactions are not supported.');
        }
        $this->db->beginTransaction();
        try {
            $this->db->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            $this->db->exec('SET LOCAL synchronous_commit = on');
            $result = $operation();
            $this->db->commit();

            return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) {
                $this->db->rollBack();
            }
            throw $error;
        }
    }

    private static function identity(ToolCallIdentity $identity): void
    {
        self::nonempty($identity->chatId);
        self::nonempty($identity->callId);
        self::nonempty($identity->toolName);
        if ($identity->turnId !== null) {
            self::nonempty($identity->turnId);
        }
    }

    private static function barrier(string $chatId, string $kind, string $id): void
    {
        self::nonempty($chatId);
        self::nonempty($id);
        if (! in_array($kind, ['call', 'turn', 'task'], true)) {
            throw new \InvalidArgumentException('Unknown tool replay barrier kind.');
        }
    }

    private static function nonempty(string $value): void
    {
        if (trim($value) === '' || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Tool state identities must be nonempty and contain no null bytes.');
        }
    }

    private static function prefix(string $prefix): void
    {
        if (! preg_match('/^[a-z][a-z0-9_]{0,47}$/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid PostgreSQL tool state table prefix.');
        }
    }
}
