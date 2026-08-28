<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ReadyManifest;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Sync\State\SyncLock;
use Digitalygo\Envai\Sync\State\SyncStateStore;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;

/**
 * Laravel-backed SyncStateStore (project document section 17.3).
 *
 * Wraps an Illuminate connection (ConnectionInterface, for example the
 * connection returned by `DB::connection($name)`) and speaks the same
 * logical schema as PdoSyncStateStore, with the table prefix defaulting
 * to `envai_sync_`:
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
 * The published migration creates the two documented state tables
 * ({prefix}resources and {prefix}manifests); the {prefix}locks table is
 * an implementation detail of the mutex strategy (the file store locks
 * through the filesystem, the in-memory store through in-process tokens)
 * and is ensured lazily by the store on the first lock acquisition
 * through the fluent schema builder (idempotent, see ensureLocksSchema).
 * Callers that prefer the migration to own every table may create the
 * locks table themselves and still rely on the store's ensureLocksSchema
 * (it is a no-op once the table exists).
 *
 * All data operations go through the query builder: reads with
 * where()/first()/get(), writes with upsert() keyed by the unique
 * constraint, deletions with delete(). The store never runs raw DDL or
 * DML strings. last_applied_payload and remote_marker are JSON-encoded
 * with plain json_encode (payloads are already normalized upstream) and
 * decoded strictly with JSON_THROW_ON_ERROR, preserving the
 * object-versus-array distinction exactly like the PDO store.
 *
 * Locking is table-based and portable, with the same semantics as
 * PdoSyncStateStore: acquireLock() inserts a row keyed by connection; a
 * second acquisition hits the unique constraint and waits up to
 * lockTimeoutSeconds (the acquisition wait), then throws
 * RuntimeException. A row is only stolen when its age exceeds
 * staleLockSeconds (default 3600.0, independent of the acquisition
 * timeout): an apply that runs longer than the acquisition wait must not
 * be taken over just because a contender waited that long, so the stale
 * threshold is a separate knob. Multi-node deployments must keep
 * applies short or serialize external work; full fencing tokens are a
 * future evolution. release() deletes the row by connection + owner, so
 * a stale takeover cannot be undone by the previous holder. On SQLite, an
 * in-memory database is private to one PDO connection: cross-connection
 * locking requires a file-backed database.
 *
 * The store never stores credentials; connection-level data may only be
 * persisted inside the manifests.metadata JSON object.
 */
final class LaravelDatabaseSyncStateStore implements SyncStateStore
{
    private const TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    private bool $lockSchemaEnsured = false;

    public function __construct(
        private readonly ConnectionInterface $connection,
        private readonly string $tablePrefix = 'envai_sync_',
        private readonly float $lockTimeoutSeconds = 10.0,
        private readonly float $staleLockSeconds = 3600.0,
        private readonly ?\Closure $nowProvider = null,
    ) {
        if ($tablePrefix === '' || preg_match('/^[A-Za-z0-9_]+$/', $tablePrefix) !== 1) {
            throw new \InvalidArgumentException('The table prefix must be a non-empty SQL identifier fragment (letters, digits, underscores).');
        }
    }

    /**
     * Creates the {prefix}locks table when it does not exist yet.
     * Idempotent: safe to call any number of times, from any number of
     * store instances sharing the same tables. The resources and
     * manifests tables are created by the published migration and are
     * never touched here.
     *
     * The connection must expose getSchemaBuilder() (every concrete
     * Illuminate\Database\Connection does); a custom ConnectionInterface
     * implementation without it makes the lock schema impossible to
     * ensure and acquireLock() fails with a clear error.
     */
    public function ensureLocksSchema(): void
    {
        if ($this->lockSchemaEnsured) {
            return;
        }

        if (! method_exists($this->connection, 'getSchemaBuilder')) {
            throw new \RuntimeException(sprintf(
                'Cannot ensure the "%s" locks table: the connection does not expose getSchemaBuilder(). Run the envai migration or pass a concrete Illuminate\\Database\\Connection.',
                $this->locksTable(),
            ));
        }

        $schema = $this->connection->getSchemaBuilder();

        if (! $schema instanceof Builder) {
            throw new \RuntimeException(sprintf(
                'Cannot ensure the "%s" locks table: getSchemaBuilder() did not return an Illuminate\\Database\\Schema\\Builder.',
                $this->locksTable(),
            ));
        }

        if (! $schema->hasTable($this->locksTable())) {
            $schema->create($this->locksTable(), static function (Blueprint $table): void {
                $table->string('connection', 191)->primary();
                $table->string('owner', 64);
                $table->string('acquired_at', 64);
            });
        }

        $this->lockSchemaEnsured = true;
    }

    public function acquireLock(string $connection): SyncLock
    {
        $this->ensureLocksSchema();

        $owner = bin2hex(random_bytes(16));
        $deadline = microtime(true) + $this->lockTimeoutSeconds;

        while (true) {
            try {
                $this->connection->table($this->locksTable())->insert([
                    'connection' => $connection,
                    'owner' => $owner,
                    'acquired_at' => $this->now()->format(self::TIME_FORMAT),
                ]);

                return new LaravelDatabaseSyncLock($this, $connection, $owner);
            } catch (QueryException $exception) {
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
                    $this->connection->table($this->locksTable())
                        ->where('connection', $connection)
                        ->where('acquired_at', $acquiredAt)
                        ->delete();

                    continue;
                }

                usleep(50_000);
            }
        }
    }

    public function getResource(string $connection, ResourceType $type, string $localKey): ?ManagedResourceState
    {
        $row = $this->connection->table($this->resourcesTable())
            ->where('connection', $connection)
            ->where('resource_type', $type->value)
            ->where('local_key', $localKey)
            ->first();

        if ($row === null) {
            return null;
        }

        return $this->stateFromRow((array) $row);
    }

    public function resources(string $connection): iterable
    {
        $rows = $this->connection->table($this->resourcesTable())
            ->where('connection', $connection)
            ->orderBy('resource_type')
            ->orderBy('local_key')
            ->get();

        foreach ($rows as $row) {
            yield $this->stateFromRow((array) $row);
        }
    }

    public function saveResource(ManagedResourceState $state): void
    {
        $now = $this->now()->format(self::TIME_FORMAT);
        $payload = $state->lastAppliedPayload === null ? null : $this->encodeJson(self::payloadToJsonValue($state->lastAppliedPayload));
        $marker = $state->remoteMarker === null ? null : $this->encodeJson(self::payloadToJsonValue($state->remoteMarker));
        $synchronizedAt = $state->synchronizedAt?->format(self::TIME_FORMAT);

        $this->connection->table($this->resourcesTable())->upsert(
            [[
                'connection' => $state->connection,
                'resource_type' => $state->type->value,
                'local_key' => $state->localKey,
                'remote_id' => $state->remoteId,
                'remote_name' => $state->remoteName,
                'desired_hash' => $state->desiredHash,
                'last_applied_payload' => $payload,
                'remote_marker' => $marker,
                'status' => $state->status->value,
                'synchronized_at' => $synchronizedAt,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['connection', 'resource_type', 'local_key'],
            ['remote_id', 'remote_name', 'desired_hash', 'last_applied_payload', 'remote_marker', 'status', 'synchronized_at', 'updated_at'],
        );
    }

    public function deleteResource(string $connection, ResourceType $type, string $localKey): void
    {
        $this->connection->table($this->resourcesTable())
            ->where('connection', $connection)
            ->where('resource_type', $type->value)
            ->where('local_key', $localKey)
            ->delete();
    }

    /**
     * Marks the given manifest hash as the last ready manifest for the
     * connection, recording the synchronization timestamp. The optional
     * metadata is persisted in the manifests.metadata JSON column and
     * round-trips through readyManifest(); this is the only place a
     * base URL or other connection-level data may be stored (never
     * credentials).
     *
     * @param  array<string, mixed>  $metadata
     */
    public function markManifestReady(string $connection, string $manifestHash, array $metadata = []): void
    {
        $this->connection->table($this->manifestsTable())->upsert(
            [[
                'connection' => $connection,
                'manifest_hash' => $manifestHash,
                'synchronized_at' => $this->now()->format(self::TIME_FORMAT),
                'metadata' => $this->encodeJson($metadata),
            ]],
            'connection',
            ['manifest_hash', 'synchronized_at', 'metadata'],
        );
    }

    public function readyManifest(string $connection): ?ReadyManifest
    {
        $row = $this->connection->table($this->manifestsTable())
            ->where('connection', $connection)
            ->first();

        if ($row === null) {
            return null;
        }

        $row = (array) $row;

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
     * @internal Used by LaravelDatabaseSyncLock.
     */
    public function releaseLock(string $connection, string $owner): void
    {
        $this->connection->table($this->locksTable())
            ->where('connection', $connection)
            ->where('owner', $owner)
            ->delete();
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

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = $exception->errorInfo[0] ?? null;

        if (! is_string($sqlState)) {
            $previous = $exception->getPrevious();

            if ($previous instanceof \PDOException) {
                $sqlState = $previous->errorInfo[0] ?? null;
            }
        }

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
        $value = $this->connection->table($this->locksTable())
            ->where('connection', $connection)
            ->value('acquired_at');

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new \RuntimeException('The lock table has an invalid acquired_at value.');
        }

        return $value;
    }

    private function encodeJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Cannot JSON-encode a state value: '.$exception->getMessage(), previous: $exception);
        }
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
}

/**
 * Lock token handed out by LaravelDatabaseSyncStateStore. release() is
 * idempotent and deletes the lock row only when the owner token still
 * matches, so a stale takeover by another process cannot be undone
 * accidentally.
 */
final class LaravelDatabaseSyncLock implements SyncLock
{
    private bool $released = false;

    public function __construct(
        private readonly LaravelDatabaseSyncStateStore $store,
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
