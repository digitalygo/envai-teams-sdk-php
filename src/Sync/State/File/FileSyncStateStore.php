<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\State\File;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Sync\State\InvalidatesReadyManifest;
use Digitalygo\Envai\Sync\State\ManagedResourceState;
use Digitalygo\Envai\Sync\State\ManagedResourceStatus;
use Digitalygo\Envai\Sync\State\ReadyManifest;
use Digitalygo\Envai\Sync\State\ResourceType;
use Digitalygo\Envai\Sync\State\SyncLock;
use Digitalygo\Envai\Sync\State\SyncStateStore;

/**
 * File-backed SyncStateStore.
 *
 * The state lives in a versioned JSON file, by default `.envai`:
 *
 * ```json
 * {"format": 1, "connections": {"<connection>": {
 *     "base_url": "https://api.envai.example"|null,
 *     "ready_manifest": "sha256:..."|null,
 *     "resources": {"<type>:<localKey>": {
 *         "remote_id": ..., "remote_name": ..., "desired_hash": ...,
 *         "last_applied": ..., "remote_marker": ..., "status": ...,
 *         "synchronized_at": ..., "updated_at": ...
 *     }}
 * }}}
 * ```
 *
 * Guarantees:
 *
 * - Atomic writes: the document is serialized to a temporary file in the
 *   same directory (pid + uniqid suffix), chmod 0600, then rename()d over
 *   the target. A crash between the temp write and the rename leaves an
 *   orphan temp that the next construction ignores (the last valid state
 *   file wins) and removes.
 * - Fail-closed reads: a non-empty file that is not valid JSON, or whose
 *   format version is unknown, makes every operation throw a
 *   RuntimeException ("envai state file is corrupt ..."). The store never
 *   silently recreates a corrupt file. An empty (or whitespace-only) file
 *   is treated as absent and yields a fresh store.
 * - Lossless rewrites: the full decoded document is preserved and only
 *   the known paths are mutated, so unknown future fields (top-level,
 *   per connection, per resource) survive a rewrite and additive format
 *   upgrades stay possible.
 * - Locking: acquireLock() uses flock on a dedicated `<path>.lock` file
 *   (never the state file itself, whose rename would break inode
 *   identity), blocking up to lockTimeoutSeconds before throwing
 *   RuntimeException. The lock guards the whole state file, i.e. every
 *   connection it contains; release() releases the flock only and never
 *   unlinks the lock file. Unlinking between cycles would reintroduce
 *   the stale-inode takeover race (a waiter blocked on the old inode and
 *   a contender on a fresh inode could hold separate locks), so the
 *   lock file persists on disk by design: it is created on the first
 *   acquisition and reused on every cycle.
 * - Permissions: the state file and the lock file are created with mode
 *   0600 when the platform supports it.
 * - No credentials: the format has no credential field. base_url holds a
 *   plain origin (scheme, host, optional port and path) and an apiKey or
 *   secret must never be passed to setBaseUrl() nor stored in any
 *   payload the caller persists.
 *
 * Consumers of saveResource(), deleteResource() and markManifestReady()
 * are expected to hold the connection lock for the whole read-modify-
 * write cycle; the store does not enforce it, but every individual write
 * is atomic. Each store instance reloads the file on every operation, so
 * a fresh instance observes changes made by other instances.
 *
 * Store-specific notes:
 *
 * - Resource metadata persists the durable mutation journal. The file format
 *   version 1 accepts an optional state_metadata object for older files.
 * - The file format records only the manifest hash, so
 *   ReadyManifest::synchronizedAt is the time the record was read, not a
 *   persisted timestamp.
 * - remoteMarker is opaque: object-shaped markers come back as decoded
 *   stdClass instances, list markers as arrays, strings as strings.
 * - updated_at per resource is a bookkeeping write timestamp; it is not
 *   exposed by the contract.
 */
final class FileSyncStateStore implements InvalidatesReadyManifest, SyncStateStore
{
    private const FORMAT = 1;

    private const TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    public function __construct(
        private readonly string $path,
        private readonly float $lockTimeoutSeconds = 10.0,
        private readonly ?\Closure $nowProvider = null,
    ) {
        if ($path === '') {
            throw new \InvalidArgumentException('State file path must not be empty.');
        }

        $directory = dirname($path);

        if (! is_dir($directory)) {
            throw new \InvalidArgumentException(sprintf('State file directory "%s" does not exist.', $directory));
        }

        $this->discardOrphanedTemporaryFiles();
    }

    public function acquireLock(string $connection): SyncLock
    {
        $handle = @fopen($this->lockPath(), 'c');

        if ($handle === false) {
            throw new \RuntimeException(sprintf('Cannot open envai state lock file "%s".', $this->lockPath()));
        }

        @chmod($this->lockPath(), 0600);

        $deadline = microtime(true) + $this->lockTimeoutSeconds;

        while (true) {
            if (flock($handle, LOCK_EX | LOCK_NB)) {
                return new FileSyncLock($handle, $connection);
            }

            if (microtime(true) >= $deadline) {
                fclose($handle);

                throw new \RuntimeException(sprintf(
                    'could not acquire envai state lock for connection "%s" (lock file "%s")',
                    $connection,
                    $this->lockPath(),
                ));
            }

            usleep(50_000);
        }
    }

    public function getResource(string $connection, ResourceType $type, string $localKey): ?ManagedResourceState
    {
        $entry = $this->resourceEntry($connection, $type, $localKey);

        return $entry === null
            ? null
            : $this->stateFromEntry($connection, $type, $localKey, $entry);
    }

    public function resources(string $connection): iterable
    {
        $node = $this->connectionNode($connection);

        if ($node === null) {
            return;
        }

        $resources = $node->resources ?? null;

        if (! $resources instanceof \stdClass) {
            throw $this->corrupt(sprintf('resources of connection "%s" must be a JSON object', $connection));
        }

        foreach (get_object_vars($resources) as $key => $entry) {
            if (! is_string($key) || ! $entry instanceof \stdClass) {
                throw $this->corrupt(sprintf('resource entry of connection "%s" must be a JSON object', $connection));
            }

            $colon = strpos($key, ':');

            if ($colon === false) {
                throw $this->corrupt(sprintf('resource key "%s" of connection "%s" must be "<type>:<localKey>"', $key, $connection));
            }

            $typeName = substr($key, 0, $colon);
            $type = ResourceType::tryFrom($typeName);

            if ($type === null) {
                throw $this->corrupt(sprintf('unknown resource type "%s" in connection "%s"', $typeName, $connection));
            }

            yield $this->stateFromEntry($connection, $type, substr($key, $colon + 1), $entry);
        }
    }

    public function saveResource(ManagedResourceState $state): void
    {
        $document = $this->loadDocument();
        $node = $this->connectionNodeOrCreate($document, $state->connection);
        $resources = $node->resources ?? null;

        if (! $resources instanceof \stdClass) {
            throw $this->corrupt(sprintf('resources of connection "%s" must be a JSON object', $state->connection));
        }

        $key = $state->type->value.':'.$state->localKey;
        $existing = $resources->{$key} ?? null;
        $entry = $existing instanceof \stdClass ? clone $existing : new \stdClass;

        // Mutate only the known paths so unknown future fields survive.
        $entry->remote_id = $state->remoteId;
        $entry->remote_name = $state->remoteName;
        $entry->desired_hash = $state->desiredHash;
        $entry->last_applied = self::payloadToJsonValue($state->lastAppliedPayload);
        $entry->remote_marker = self::payloadToJsonValue($state->remoteMarker);
        $entry->status = $state->status->value;
        $entry->state_metadata = self::payloadToJsonValue(new JsonObject($state->metadata));
        $entry->synchronized_at = $state->synchronizedAt?->format(self::TIME_FORMAT);
        $entry->updated_at = $this->now()->format(self::TIME_FORMAT);
        $resources->{$key} = $entry;

        $this->writeDocument($document);
    }

    public function deleteResource(string $connection, ResourceType $type, string $localKey): void
    {
        $document = $this->loadDocument();
        $connections = $document->connections;

        if (! $connections instanceof \stdClass) {
            throw $this->corrupt('connections must be a JSON object');
        }

        $node = $connections->{$connection} ?? null;

        if (! $node instanceof \stdClass) {
            return; // Unknown connection: no-op.
        }

        $resources = $node->resources ?? null;

        if (! $resources instanceof \stdClass) {
            throw $this->corrupt(sprintf('resources of connection "%s" must be a JSON object', $connection));
        }

        $key = $type->value.':'.$localKey;

        if (! isset($resources->{$key})) {
            return; // Unknown resource: no-op.
        }

        unset($resources->{$key});
        $this->writeDocument($document);
    }

    public function clearManifestReady(string $connection): void
    {
        $document = $this->loadDocument();
        $node = $this->connectionNodeOrCreate($document, $connection);
        $node->ready_manifest = null;
        $this->writeDocument($document);
    }

    public function markManifestReady(string $connection, string $manifestHash): void
    {
        $document = $this->loadDocument();
        $node = $this->connectionNodeOrCreate($document, $connection);
        $node->ready_manifest = $manifestHash;
        $this->writeDocument($document);
    }

    public function readyManifest(string $connection): ?ReadyManifest
    {
        $node = $this->connectionNode($connection);

        if ($node === null) {
            return null;
        }

        $hash = $node->ready_manifest ?? null;

        if ($hash === null) {
            return null;
        }

        if (! is_string($hash) || $hash === '') {
            throw $this->corrupt(sprintf('ready_manifest of connection "%s" must be a non-empty string', $connection));
        }

        return new ReadyManifest(
            connection: $connection,
            manifestHash: $hash,
            formatVersion: '1',
            synchronizedAt: $this->now(),
            metadata: [],
        );
    }

    /**
     * The plain origin base URL recorded for the connection, or null when
     * the connection has none. This is an extension of the file store
     * (the SyncStateStore interface has no base URL methods); the value
     * must never carry credentials.
     */
    public function baseUrl(string $connection): ?string
    {
        $node = $this->connectionNode($connection);

        if ($node === null) {
            return null;
        }

        $baseUrl = $node->base_url ?? null;

        if ($baseUrl === null) {
            return null;
        }

        if (! is_string($baseUrl)) {
            throw $this->corrupt(sprintf('base_url of connection "%s" must be a string or null', $connection));
        }

        return $baseUrl;
    }

    /**
     * Records the plain origin base URL for the connection, replacing any
     * previous value. Pass null to clear it. Credential-bearing strings
     * (userinfo, query, fragment) are rejected; an apiKey must never be
     * passed here.
     */
    public function setBaseUrl(string $connection, ?string $baseUrl): void
    {
        if ($baseUrl !== null) {
            self::assertPlainOrigin($baseUrl);
        }

        $document = $this->loadDocument();
        $node = $this->connectionNodeOrCreate($document, $connection);
        $node->base_url = $baseUrl;
        $this->writeDocument($document);
    }

    private function resourceEntry(string $connection, ResourceType $type, string $localKey): ?\stdClass
    {
        $node = $this->connectionNode($connection);

        if ($node === null) {
            return null;
        }

        $resources = $node->resources ?? null;

        if (! $resources instanceof \stdClass) {
            throw $this->corrupt(sprintf('resources of connection "%s" must be a JSON object', $connection));
        }

        $entry = $resources->{$type->value.':'.$localKey} ?? null;

        if ($entry === null) {
            return null;
        }

        if (! $entry instanceof \stdClass) {
            throw $this->corrupt(sprintf(
                'resource entry "%s:%s" of connection "%s" must be a JSON object',
                $type->value,
                $localKey,
                $connection,
            ));
        }

        return $entry;
    }

    private function connectionNode(string $connection): ?\stdClass
    {
        $document = $this->loadDocument();
        $connections = $document->connections;

        if (! $connections instanceof \stdClass) {
            throw $this->corrupt('connections must be a JSON object');
        }

        $node = $connections->{$connection} ?? null;

        if ($node === null) {
            return null;
        }

        if (! $node instanceof \stdClass) {
            throw $this->corrupt(sprintf('connection "%s" must be a JSON object', $connection));
        }

        return $node;
    }

    private function connectionNodeOrCreate(\stdClass $document, string $connection): \stdClass
    {
        $connections = $document->connections;

        if (! $connections instanceof \stdClass) {
            throw $this->corrupt('connections must be a JSON object');
        }

        $existing = $connections->{$connection} ?? null;

        if ($existing instanceof \stdClass) {
            return $existing;
        }

        if ($existing !== null) {
            throw $this->corrupt(sprintf('connection "%s" must be a JSON object', $connection));
        }

        $node = new \stdClass;
        $node->base_url = null;
        $node->ready_manifest = null;
        $node->resources = new \stdClass;
        $connections->{$connection} = $node;

        return $node;
    }

    private function stateFromEntry(string $connection, ResourceType $type, string $localKey, \stdClass $entry): ManagedResourceState
    {
        $statusValue = $entry->status ?? null;

        if (! is_string($statusValue)) {
            throw $this->corrupt(sprintf('resource "%s:%s" of connection "%s" has a missing or invalid status', $type->value, $localKey, $connection));
        }

        $status = ManagedResourceStatus::tryFrom($statusValue);

        if ($status === null) {
            throw $this->corrupt(sprintf('resource "%s:%s" of connection "%s" has an unknown status "%s"', $type->value, $localKey, $connection, $statusValue));
        }

        return new ManagedResourceState(
            connection: $connection,
            type: $type,
            localKey: $localKey,
            remoteId: $this->nullableStringMember($entry, 'remote_id', $connection, $type, $localKey),
            remoteName: $this->nullableStringMember($entry, 'remote_name', $connection, $type, $localKey),
            desiredHash: $this->nullableStringMember($entry, 'desired_hash', $connection, $type, $localKey),
            lastAppliedPayload: self::jsonValueToPayload($entry->last_applied ?? null),
            remoteMarker: $entry->remote_marker ?? null,
            synchronizedAt: $this->nullableTimestampMember($entry, 'synchronized_at', $connection, $type, $localKey),
            status: $status,
            metadata: self::metadataFromEntry($entry),
        );
    }

    /** @return array<string, mixed> */
    private static function metadataFromEntry(\stdClass $entry): array
    {
        $value = self::jsonValueToPayload($entry->state_metadata ?? new \stdClass);
        if ($value instanceof JsonObject) {
            $metadata = $value->toArray();
            foreach ($metadata as $key => $_) {
                if (! is_string($key)) {
                    throw new \RuntimeException('Invalid persisted resource metadata key.');
                }
            }

            /** @var array<string, mixed> $metadata */
            return $metadata;
        }
        if ($value === []) {
            return [];
        }
        throw new \RuntimeException('Invalid persisted resource metadata.');
    }

    private function nullableStringMember(\stdClass $entry, string $member, string $connection, ResourceType $type, string $localKey): ?string
    {
        $value = $entry->{$member} ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw $this->corrupt(sprintf('"%s" of resource "%s:%s" of connection "%s" must be a string or null', $member, $type->value, $localKey, $connection));
        }

        return $value;
    }

    private function nullableTimestampMember(\stdClass $entry, string $member, string $connection, ResourceType $type, string $localKey): ?\DateTimeImmutable
    {
        $value = $entry->{$member} ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw $this->corrupt(sprintf('"%s" of resource "%s:%s" of connection "%s" must be a string or null', $member, $type->value, $localKey, $connection));
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception $exception) {
            throw $this->corrupt(sprintf('"%s" of resource "%s:%s" of connection "%s" is not a valid timestamp', $member, $type->value, $localKey, $connection), $exception);
        }
    }

    private function loadDocument(): \stdClass
    {
        if (! is_file($this->path)) {
            return self::emptyDocument();
        }

        $contents = file_get_contents($this->path);

        if ($contents === false) {
            throw new \RuntimeException(sprintf('Cannot read envai state file "%s".', $this->path));
        }

        // A zero-byte (or whitespace-only) file is treated as absent.
        if (trim($contents) === '') {
            return self::emptyDocument();
        }

        try {
            $document = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException(sprintf(
                'envai state file is corrupt: "%s" (invalid JSON: %s)',
                $this->path,
                $exception->getMessage(),
            ), previous: $exception);
        }

        if (! $document instanceof \stdClass) {
            throw $this->corrupt('the top-level value must be a JSON object');
        }

        if (($document->format ?? null) !== self::FORMAT) {
            throw $this->corrupt('the format version is missing or unsupported');
        }

        if (($document->connections ?? null) instanceof \stdClass) {
            return $document;
        }

        throw $this->corrupt('connections must be a JSON object');
    }

    private function writeDocument(\stdClass $document): void
    {
        try {
            $json = json_encode($document, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Cannot serialize the envai state document: '.$exception->getMessage(), previous: $exception);
        }

        $temporary = $this->path.'.tmp.'.getmypid().'.'.uniqid('', true);

        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            @unlink($temporary);

            throw new \RuntimeException(sprintf('Cannot write the temporary envai state file "%s".', $temporary));
        }

        @chmod($temporary, 0600);

        if (! rename($temporary, $this->path)) {
            @unlink($temporary);

            throw new \RuntimeException(sprintf('Cannot atomically replace the envai state file "%s".', $this->path));
        }

        @chmod($this->path, 0600);
    }

    private function discardOrphanedTemporaryFiles(): void
    {
        $temporaries = glob($this->path.'.tmp.*', GLOB_NOSORT);

        if ($temporaries === false) {
            return;
        }

        foreach ($temporaries as $temporary) {
            @unlink($temporary);
        }
    }

    private function lockPath(): string
    {
        return $this->path.'.lock';
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

    private static function emptyDocument(): \stdClass
    {
        $document = new \stdClass;
        $document->format = self::FORMAT;
        $document->connections = new \stdClass;

        return $document;
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
                $data[$key] = self::jsonValueToPayload($item);
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
     * the object-versus-array distinction: empty JsonObject encodes as
     * `{}`, empty JsonArray as `[]`.
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

    private static function assertPlainOrigin(string $baseUrl): void
    {
        if ($baseUrl === '') {
            throw new \InvalidArgumentException('base_url must be an absolute http(s) origin URL or null.');
        }

        $parts = parse_url($baseUrl);

        if (! is_array($parts)) {
            throw new \InvalidArgumentException(sprintf('base_url "%s" is not a valid URL.', $baseUrl));
        }

        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;

        if (($scheme !== 'http' && $scheme !== 'https') || ! is_string($host) || $host === '') {
            throw new \InvalidArgumentException(sprintf('base_url "%s" must be an absolute http(s) origin URL.', $baseUrl));
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \InvalidArgumentException(sprintf('base_url "%s" must not contain credentials, a query or a fragment.', $baseUrl));
        }
    }

    private function corrupt(string $reason, ?\Throwable $previous = null): \RuntimeException
    {
        return new \RuntimeException(sprintf('envai state file is corrupt: "%s" (%s)', $this->path, $reason), previous: $previous);
    }
}

/**
 * flock-based lock token handed out by FileSyncStateStore. release() is
 * idempotent: it releases the flock and closes the handle. The lock file
 * itself is never unlinked (see the owning store's locking notes):
 * unlinking would reintroduce the stale-inode race where a waiter on the
 * old inode and a contender on a fresh inode hold separate locks, so the
 * file persists on disk by design.
 */
final class FileSyncLock implements SyncLock
{
    private bool $released = false;

    /**
     * @param  resource  $handle  Open handle of the dedicated lock file.
     */
    public function __construct(
        private readonly mixed $handle,
        private readonly string $connection,
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
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
    }

    public function isReleased(): bool
    {
        return $this->released;
    }
}
