<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\State\Pdo;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ReadyManifest;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Sync\State\SyncLock;
use Digitalygo\Envai\Sync\State\SyncStateStore;

/**
 * PDO-backed SyncStateStore (project document section 17.3).
 *
 * Logical schema, with the table prefix defaulting to `envai_sync_`:
 *
 * ```text
 * {prefix}resources
 *   connection, resource_type, local_key, remote_id, remote_name,
 *   desired_hash, last_applied_payload (JSON text), remote_marker (JSON
 *   text), status, synchronized_at, created_at, updated_at
 *   unique key: (connection, resource_type, local_key)
 *
 * {prefix}manifests
 *   connection, manifest_hash, synchronized_at, metadata (JSON)
 *   one row per connection: the last manifest marked ready
 *
 * {prefix}locks
 *   connection, owner, acquired_at
 *   one row per held lock: INSERT ... unique(connection)
 * ```
 *
 * Supported drivers: sqlite, mysql (MySQL 5.7+ / MariaDB 10.2+),
 * pgsql. The driver is detected through PDO::ATTR_DRIVER_NAME; any other
 * driver throws in the constructor.
 *
 * ensureSchema() runs the idempotent CREATE TABLE IF NOT EXISTS DDL and
 * is called lazily once before the first operation only when the
 * constructor flag $autoMigrate is true (default false). With
 * $autoMigrate = false an operation before ensureSchema() throws a
 * RuntimeException that tells the caller to create the schema or enable
 * auto migration. The store sets PDO::ATTR_ERRMODE to ERRMODE_EXCEPTION
 * on the supplied PDO.
 *
 * All reads and writes go through prepared statements. Resource saves are
 * upserts by the unique key: INSERT ... ON CONFLICT (sqlite, pgsql) or
 * INSERT ... ON DUPLICATE KEY UPDATE (mysql). last_applied_payload and
 * remote_marker are JSON-encoded with plain json_encode (payloads are
 * already normalized upstream) and decoded strictly with
 * JSON_THROW_ON_ERROR; object literals come back as JsonObject, array
 * literals as JsonArray, so `{}`, `[]` and null stay distinct.
 *
 * Locking is table-based and portable: acquireLock() inserts a row keyed
 * by connection; a second acquisition hits the unique constraint and
 * waits up to lockTimeoutSeconds (the acquisition wait), then throws
 * RuntimeException. A row is only stolen when its age exceeds
 * staleLockSeconds (default 3600.0, independent of the acquisition
 * timeout): an apply that runs longer than the acquisition wait must not
 * be taken over just because a contender waited that long, so the stale
 * threshold is a separate knob. Multi-node deployments must keep
 * applies short or serialize external work; full fencing tokens are a
 * future evolution. release() deletes the row by connection + owner, so
 * a stale takeover cannot be undone by the previous holder. Caveats per
 * platform:
 *
 * - PostgreSQL: advisory locks need a transaction, hence the table-based
 *   lock; the lock row is visible to every process sharing the database.
 * - MySQL/MariaDB: row locks behave like the InnoDB transaction model;
 *   the INSERT ... unique constraint is atomic across connections.
 * - SQLite: the locks table works across PDO connections to the same
 *   database file, but an in-memory database (`sqlite::memory:`) is
 *   private to one PDO connection, so cross-connection locking requires a
 *   file-backed database.
 *
 * The store never stores credentials. There is no credential column;
 * connection-level data such as a base URL may only be persisted inside
 * the manifests.metadata JSON object through markManifestReady()'s
 * optional metadata argument (ReadyManifest::metadata round-trips).
 * metadata of ManagedResourceState is not persisted (no column for it).
 */
final class PdoSyncStateStore implements SyncStateStore
{
    private const TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    private const RESOURCE_COLUMNS = 'connection, resource_type, local_key, remote_id, remote_name, desired_hash, last_applied_payload, remote_marker, status, synchronized_at';

    private readonly string $driver;

    private bool $schemaEnsured = false;

    public function __construct(
        private readonly \PDO $pdo,
        private readonly string $tablePrefix = 'envai_sync_',
        private readonly float $lockTimeoutSeconds = 10.0,
        private readonly float $staleLockSeconds = 3600.0,
        private readonly bool $autoMigrate = false,
        private readonly ?\Closure $nowProvider = null,
    ) {
        if ($tablePrefix === '' || preg_match('/^[A-Za-z0-9_]+$/', $tablePrefix) !== 1) {
            throw new \InvalidArgumentException('The table prefix must be a non-empty SQL identifier fragment (letters, digits, underscores).');
        }

        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $driver = $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if (! is_string($driver)) {
            throw new \RuntimeException('Cannot detect the PDO driver name.');
        }

        if (! in_array($driver, ['sqlite', 'mysql', 'pgsql'], true)) {
            throw new \RuntimeException(sprintf('PdoSyncStateStore supports sqlite, mysql and pgsql drivers only; got "%s".', $driver));
        }

        $this->driver = $driver;
    }

    /**
     * Creates the resources, manifests and locks tables. Idempotent:
     * safe to call any number of times, from any number of store
     * instances sharing the same tables.
     */
    public function ensureSchema(): void
    {
        foreach ([$this->resourcesTableSql(), $this->manifestsTableSql(), $this->locksTableSql()] as $sql) {
            $this->pdo->exec($sql);
        }

        $this->schemaEnsured = true;
    }

    public function acquireLock(string $connection): SyncLock
    {
        $this->schema();

        $owner = bin2hex(random_bytes(16));
        $deadline = microtime(true) + $this->lockTimeoutSeconds;

        while (true) {
            try {
                $statement = $this->prepare(sprintf(
                    'INSERT INTO %s (connection, owner, acquired_at) VALUES (?, ?, ?)',
                    $this->locksTable(),
                ));
                $statement->execute([$connection, $owner, $this->now()->format(self::TIME_FORMAT)]);

                return new PdoSyncLock($this, $connection, $owner);
            } catch (\PDOException $exception) {
                if (! $this->isUniqueViolation($exception)) {
                    throw $exception;
                }

                if (microtime(true) >= $deadline) {
                    throw new \RuntimeException(sprintf(
                        'could not acquire envai state lock for connection "%s": the lock is held by another process (lock table "%s")',
                        $connection,
                        $this->locksTable(),
                    ));
                }

                $acquiredAt = $this->fetchLockAcquiredAt($connection);

                if ($acquiredAt === null) {
                    continue; // The holder released between our attempts.
                }

                if ($this->isStale($acquiredAt)) {
                    $this->prepare(sprintf('DELETE FROM %s WHERE connection = ? AND acquired_at = ?', $this->locksTable()))
                        ->execute([$connection, $acquiredAt]);

                    continue;
                }

                usleep(50_000);
            }
        }
    }

    public function getResource(string $connection, ResourceType $type, string $localKey): ?ManagedResourceState
    {
        $this->schema();

        $statement = $this->prepare(sprintf(
            'SELECT %s FROM %s WHERE connection = ? AND resource_type = ? AND local_key = ?',
            self::RESOURCE_COLUMNS,
            $this->resourcesTable(),
        ));
        $statement->execute([$connection, $type->value, $localKey]);

        $row = $this->fetchAssoc($statement);

        if ($row === null) {
            return null;
        }

        return $this->stateFromRow($row);
    }

    public function resources(string $connection): iterable
    {
        $this->schema();

        $statement = $this->prepare(sprintf(
            'SELECT %s FROM %s WHERE connection = ? ORDER BY resource_type, local_key',
            self::RESOURCE_COLUMNS,
            $this->resourcesTable(),
        ));
        $statement->execute([$connection]);

        while (($row = $this->fetchAssoc($statement)) !== null) {
            yield $this->stateFromRow($row);
        }
    }

    public function saveResource(ManagedResourceState $state): void
    {
        $this->schema();

        $now = $this->now()->format(self::TIME_FORMAT);
        $payload = $state->lastAppliedPayload === null ? null : $this->encodeJson(self::payloadToJsonValue($state->lastAppliedPayload));
        $marker = $state->remoteMarker === null ? null : $this->encodeJson(self::payloadToJsonValue($state->remoteMarker));
        $synchronizedAt = $state->synchronizedAt?->format(self::TIME_FORMAT);

        $statement = $this->prepare($this->upsertResourcesSql());
        $params = [
            $state->connection,
            $state->type->value,
            $state->localKey,
            $state->remoteId,
            $state->remoteName,
            $state->desiredHash,
            $payload,
            $marker,
            $state->status->value,
            $synchronizedAt,
            $now,
            $now,
        ];

        if ($this->driver !== 'mysql') {
            // ON CONFLICT ... DO UPDATE placeholders repeat the update columns.
            array_push($params, $state->remoteId, $state->remoteName, $state->desiredHash, $payload, $marker, $state->status->value, $synchronizedAt, $now);
        }

        $statement->execute($params);
    }

    public function deleteResource(string $connection, ResourceType $type, string $localKey): void
    {
        $this->schema();

        $statement = $this->prepare(sprintf(
            'DELETE FROM %s WHERE connection = ? AND resource_type = ? AND local_key = ?',
            $this->resourcesTable(),
        ));
        $statement->execute([$connection, $type->value, $localKey]);
    }

    /**
     * Marks the given manifest hash as the last ready manifest for the
     * connection, recording the synchronization timestamp. The optional
     * metadata is persisted in the manifests.metadata JSON column and
     * round-trips through readyManifest(); this is the only place a
     * base URL or other connection-level data may be stored (never
     * credentials). The extra optional parameter is compatible with the
     * SyncStateStore interface.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function markManifestReady(string $connection, string $manifestHash, array $metadata = []): void
    {
        $this->schema();

        $now = $this->now()->format(self::TIME_FORMAT);
        $metadataJson = $this->encodeJson($metadata);

        $statement = $this->prepare($this->upsertManifestsSql());
        $params = [$connection, $manifestHash, $now, $metadataJson];

        if ($this->driver !== 'mysql') {
            array_push($params, $manifestHash, $now, $metadataJson);
        }

        $statement->execute($params);
    }

    public function readyManifest(string $connection): ?ReadyManifest
    {
        $this->schema();

        $statement = $this->prepare(sprintf(
            'SELECT connection, manifest_hash, synchronized_at, metadata FROM %s WHERE connection = ?',
            $this->manifestsTable(),
        ));
        $statement->execute([$connection]);

        $row = $this->fetchAssoc($statement);

        if ($row === null) {
            return null;
        }

        return new ReadyManifest(
            connection: self::stringField($row, 'connection'),
            manifestHash: self::stringField($row, 'manifest_hash'),
            formatVersion: '1',
            synchronizedAt: $this->parseTimestamp(self::stringField($row, 'synchronized_at')),
            metadata: $this->decodeMetadataColumn(self::stringField($row, 'metadata')),
        );
    }

    /**
     * Releases the lock row previously inserted by acquireLock(), but
     * only when the owner token still matches (a stale takeover replaces
     * the row with another owner, so the previous holder's release is a
     * no-op).
     *
     * @internal Used by PdoSyncLock.
     */
    public function releaseLock(string $connection, string $owner): void
    {
        $this->prepare(sprintf('DELETE FROM %s WHERE connection = ? AND owner = ?', $this->locksTable()))
            ->execute([$connection, $owner]);
    }

    /**
     * Fetches the next row as an associative array, or null at the end
     * of the result set.
     *
     * @return array<array-key, mixed>|null
     */
    private function fetchAssoc(\PDOStatement $statement): ?array
    {
        $row = $statement->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $row;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function stateFromRow(array $row): ManagedResourceState
    {
        $statusValue = $row['status'] ?? null;

        if (! is_string($statusValue)) {
            throw new \RuntimeException('The state row has a missing or invalid status.');
        }

        $status = ManagedResourceStatus::tryFrom($statusValue);

        if ($status === null) {
            throw new \RuntimeException(sprintf('The state row has an unknown status "%s".', $statusValue));
        }

        $typeName = self::stringField($row, 'resource_type');
        $type = ResourceType::tryFrom($typeName);

        if ($type === null) {
            throw new \RuntimeException(sprintf('The state row has an unknown resource type "%s".', $typeName));
        }

        return new ManagedResourceState(
            connection: self::stringField($row, 'connection'),
            type: $type,
            localKey: self::stringField($row, 'local_key'),
            remoteId: self::nullableStringField($row, 'remote_id'),
            remoteName: self::nullableStringField($row, 'remote_name'),
            desiredHash: self::nullableStringField($row, 'desired_hash'),
            lastAppliedPayload: self::decodePayloadColumn(self::nullableStringField($row, 'last_applied_payload')),
            remoteMarker: self::decodeMarkerColumn(self::nullableStringField($row, 'remote_marker')),
            synchronizedAt: $this->nullableTimestampField($row, 'synchronized_at'),
            status: $status,
            metadata: [],
        );
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private static function stringField(array $row, string $column): string
    {
        $value = $row[$column] ?? null;

        if (! is_string($value) || $value === '') {
            throw new \RuntimeException(sprintf('The state row has an invalid "%s" value.', $column));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private static function nullableStringField(array $row, string $column): ?string
    {
        $value = $row[$column] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new \RuntimeException(sprintf('The state row has an invalid "%s" value.', $column));
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $row
     */
    private function nullableTimestampField(array $row, string $column): ?\DateTimeImmutable
    {
        $value = self::nullableStringField($row, $column);

        if ($value === null) {
            return null;
        }

        return $this->parseTimestamp($value);
    }

    private function parseTimestamp(string $value): \DateTimeImmutable
    {
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw new \RuntimeException('The state row has an invalid timestamp.', previous: $exception);
        }
    }

    private static function decodePayloadColumn(?string $json): mixed
    {
        if ($json === null) {
            return null;
        }

        try {
            return self::jsonValueToPayload(json_decode($json, false, 512, JSON_THROW_ON_ERROR));
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The stored last_applied_payload is not valid JSON: '.$exception->getMessage(), previous: $exception);
        }
    }

    private static function decodeMarkerColumn(?string $json): mixed
    {
        if ($json === null) {
            return null;
        }

        try {
            return json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The stored remote_marker is not valid JSON: '.$exception->getMessage(), previous: $exception);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeMetadataColumn(string $json): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('The stored manifest metadata is not valid JSON: '.$exception->getMessage(), previous: $exception);
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException('The stored manifest metadata must be a JSON object.');
        }

        $metadata = [];

        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    private function isUniqueViolation(\PDOException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        return is_string($sqlState) && str_starts_with($sqlState, '23');
    }

    private function isStale(string $acquiredAt): bool
    {
        try {
            $acquired = new \DateTimeImmutable($acquiredAt);
        } catch (\Exception) {
            return true; // Unparseable timestamps are treated as stale.
        }

        return (float) $this->now()->format('U.u') - (float) $acquired->format('U.u') > $this->staleLockSeconds;
    }

    private function fetchLockAcquiredAt(string $connection): ?string
    {
        $statement = $this->prepare(sprintf('SELECT acquired_at FROM %s WHERE connection = ?', $this->locksTable()));
        $statement->execute([$connection]);

        $value = $statement->fetchColumn();

        if ($value === false || $value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new \RuntimeException('The lock table has an invalid acquired_at value.');
        }

        return $value;
    }

    private function schema(): void
    {
        if ($this->schemaEnsured) {
            return;
        }

        if (! $this->autoMigrate) {
            throw new \RuntimeException(sprintf(
                'PdoSyncStateStore schema for prefix "%s" has not been created: call ensureSchema() or construct with $autoMigrate = true.',
                $this->tablePrefix,
            ));
        }

        $this->ensureSchema();
    }

    private function prepare(string $sql): \PDOStatement
    {
        $statement = $this->pdo->prepare($sql);

        if ($statement === false) {
            throw new \RuntimeException(sprintf('Cannot prepare a statement against the "%s" driver.', $this->driver));
        }

        return $statement;
    }

    private function encodeJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Cannot JSON-encode a state value: '.$exception->getMessage(), previous: $exception);
        }
    }

    private function upsertResourcesSql(): string
    {
        $columns = 'connection, resource_type, local_key, remote_id, remote_name, desired_hash, last_applied_payload, remote_marker, status, synchronized_at, created_at, updated_at';
        $update = match ($this->driver) {
            'mysql' => 'ON DUPLICATE KEY UPDATE remote_id = VALUES(remote_id), remote_name = VALUES(remote_name), desired_hash = VALUES(desired_hash), last_applied_payload = VALUES(last_applied_payload), remote_marker = VALUES(remote_marker), status = VALUES(status), synchronized_at = VALUES(synchronized_at), updated_at = VALUES(updated_at)',
            default => 'ON CONFLICT (connection, resource_type, local_key) DO UPDATE SET remote_id = ?, remote_name = ?, desired_hash = ?, last_applied_payload = ?, remote_marker = ?, status = ?, synchronized_at = ?, updated_at = ?',
        };

        return sprintf('INSERT INTO %s (%s) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) %s', $this->resourcesTable(), $columns, $update);
    }

    private function upsertManifestsSql(): string
    {
        $update = match ($this->driver) {
            'mysql' => 'ON DUPLICATE KEY UPDATE manifest_hash = VALUES(manifest_hash), synchronized_at = VALUES(synchronized_at), metadata = VALUES(metadata)',
            default => 'ON CONFLICT (connection) DO UPDATE SET manifest_hash = ?, synchronized_at = ?, metadata = ?',
        };

        return sprintf('INSERT INTO %s (connection, manifest_hash, synchronized_at, metadata) VALUES (?, ?, ?, ?) %s', $this->manifestsTable(), $update);
    }

    private function resourcesTableSql(): string
    {
        $columns = 'connection VARCHAR(191) NOT NULL, resource_type VARCHAR(64) NOT NULL, local_key VARCHAR(191) NOT NULL, remote_id VARCHAR(191) NULL, remote_name VARCHAR(191) NULL, desired_hash VARCHAR(191) NULL, last_applied_payload TEXT NULL, remote_marker TEXT NULL, status VARCHAR(32) NOT NULL, synchronized_at VARCHAR(64) NULL, created_at VARCHAR(64) NOT NULL, updated_at VARCHAR(64) NOT NULL, PRIMARY KEY (connection, resource_type, local_key)';

        return sprintf('CREATE TABLE IF NOT EXISTS %s (%s)%s', $this->resourcesTable(), $columns, $this->mysqlTableOptions());
    }

    private function manifestsTableSql(): string
    {
        $columns = 'connection VARCHAR(191) NOT NULL, manifest_hash VARCHAR(191) NOT NULL, synchronized_at VARCHAR(64) NOT NULL, metadata TEXT NOT NULL, PRIMARY KEY (connection)';

        return sprintf('CREATE TABLE IF NOT EXISTS %s (%s)%s', $this->manifestsTable(), $columns, $this->mysqlTableOptions());
    }

    private function locksTableSql(): string
    {
        $columns = 'connection VARCHAR(191) NOT NULL, owner VARCHAR(64) NOT NULL, acquired_at VARCHAR(64) NOT NULL, PRIMARY KEY (connection)';

        return sprintf('CREATE TABLE IF NOT EXISTS %s (%s)%s', $this->locksTable(), $columns, $this->mysqlTableOptions());
    }

    /**
     * MySQL needs InnoDB with a modern row format for a 3-column
     * VARCHAR(191) primary key under utf8mb4 (573 * 4 = 2292 bytes).
     * SQLite and PostgreSQL use their defaults.
     */
    private function mysqlTableOptions(): string
    {
        return $this->driver === 'mysql'
            ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC'
            : '';
    }

    private function resourcesTable(): string
    {
        return $this->tablePrefix.'resources';
    }

    private function manifestsTable(): string
    {
        return $this->tablePrefix.'manifests';
    }

    private function locksTable(): string
    {
        return $this->tablePrefix.'locks';
    }

    private function now(): \DateTimeImmutable
    {
        $provider = $this->nowProvider;

        if ($provider !== null) {
            $now = $provider();

            if (! $now instanceof \DateTimeImmutable) {
                throw new \InvalidArgumentException('The nowProvider closure must return a DateTimeImmutable.');
            }

            return $now;
        }

        return new \DateTimeImmutable('now');
    }

    /**
     * Converts a decoded JSON value into the read representation:
     * objects become JsonObject, arrays JsonArray, scalars and null stay
     * as they are. Empty objects stay distinguishable from empty arrays.
     */
    private static function jsonValueToPayload(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $data = [];

            foreach (get_object_vars($value) as $key => $item) {
                if (is_string($key)) {
                    $data[$key] = self::jsonValueToPayload($item);
                }
            }

            return new JsonObject($data);
        }

        if (is_array($value)) {
            $items = [];

            foreach ($value as $item) {
                $items[] = self::jsonValueToPayload($item);
            }

            return new JsonArray($items);
        }

        return $value;
    }

    /**
     * Converts a payload value (JsonObject, JsonArray, plain arrays,
     * stdClass, scalars, null) into a JSON-encodable value that preserves
     * the object-versus-array distinction.
     *
     * @throws \InvalidArgumentException when the value cannot be stored as JSON.
     */
    private static function payloadToJsonValue(mixed $value): mixed
    {
        if ($value instanceof JsonObject) {
            $object = new \stdClass;

            foreach ($value->toArray() as $key => $item) {
                $object->{$key} = self::payloadToJsonValue($item);
            }

            return $object;
        }

        if ($value instanceof JsonArray) {
            $items = [];

            foreach ($value->toArray() as $item) {
                $items[] = self::payloadToJsonValue($item);
            }

            return $items;
        }

        if (is_array($value)) {
            if (array_is_list($value)) {
                $items = [];

                foreach ($value as $item) {
                    $items[] = self::payloadToJsonValue($item);
                }

                return $items;
            }

            $object = new \stdClass;

            foreach ($value as $key => $item) {
                $object->{$key} = self::payloadToJsonValue($item);
            }

            return $object;
        }

        if ($value instanceof \stdClass) {
            $object = new \stdClass;

            foreach (get_object_vars($value) as $key => $item) {
                $object->{$key} = self::payloadToJsonValue($item);
            }

            return $object;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value) || is_string($value)) {
            return $value;
        }

        throw new \InvalidArgumentException(sprintf(
            'Payload value of type %s cannot be stored in the state store.',
            get_debug_type($value),
        ));
    }
}

/**
 * Lock token handed out by PdoSyncStateStore. release() is idempotent
 * and deletes the lock row only when the owner token still matches, so a
 * stale takeover by another process cannot be undone accidentally.
 */
final class PdoSyncLock implements SyncLock
{
    private bool $released = false;

    public function __construct(
        private readonly PdoSyncStateStore $store,
        private readonly string $connection,
        private readonly string $owner,
    ) {}

    public function connection(): string
    {
        return $this->connection;
    }

    public function release(): void
    {
        if ($this->released) {
            return;
        }

        $this->released = true;
        $this->store->releaseLock($this->connection, $this->owner);
    }

    public function isReleased(): bool
    {
        return $this->released;
    }
}
