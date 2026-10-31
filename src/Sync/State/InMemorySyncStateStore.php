<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\State;

/**
 * In-memory SyncStateStore for tests, development and single-process
 * tooling. The lock is a mutex-less token: it only guards against
 * concurrent acquisition within the same process and throws when a
 * connection is already held. Nothing is persisted.
 *
 * Resource keys are `connection:type:localKey`; manifest readiness is
 * kept per connection with the store format version "1".
 */
final class InMemorySyncStateStore implements InvalidatesReadyManifest, SyncStateStore
{
    /**
     * @var array<string, ManagedResourceState>
     */
    private array $resources = [];

    /**
     * @var array<string, ReadyManifest>
     */
    private array $manifests = [];

    /**
     * @var array<string, true>
     */
    private array $heldLocks = [];

    public function acquireLock(string $connection): SyncLock
    {
        if (isset($this->heldLocks[$connection])) {
            throw new \RuntimeException(sprintf(
                'The lock for connection "%s" is already held.',
                $connection,
            ));
        }

        $this->heldLocks[$connection] = true;

        return new InMemorySyncLock($this, $connection);
    }

    public function getResource(string $connection, ResourceType $type, string $localKey): ?ManagedResourceState
    {
        return $this->resources[self::key($connection, $type, $localKey)] ?? null;
    }

    public function resources(string $connection): iterable
    {
        $prefix = $connection.':';

        foreach ($this->resources as $key => $state) {
            if (str_starts_with($key, $prefix)) {
                yield $state;
            }
        }
    }

    public function saveResource(ManagedResourceState $state): void
    {
        $this->resources[self::key($state->connection, $state->type, $state->localKey)] = $state;
    }

    public function deleteResource(string $connection, ResourceType $type, string $localKey): void
    {
        unset($this->resources[self::key($connection, $type, $localKey)]);
    }

    public function clearManifestReady(string $connection): void
    {
        unset($this->manifests[$connection]);
    }

    public function markManifestReady(string $connection, string $manifestHash): void
    {
        $this->manifests[$connection] = new ReadyManifest(
            connection: $connection,
            manifestHash: $manifestHash,
            formatVersion: '1',
            synchronizedAt: new \DateTimeImmutable,
            metadata: [],
        );
    }

    public function readyManifest(string $connection): ?ReadyManifest
    {
        return $this->manifests[$connection] ?? null;
    }

    /**
     * Releases the lock previously acquired through acquireLock(). The
     * store calls back into this method through the lock token; calling
     * it for an unknown connection is ignored.
     *
     * @internal Used by InMemorySyncLock.
     */
    public function releaseLock(string $connection): void
    {
        unset($this->heldLocks[$connection]);
    }

    private static function key(string $connection, ResourceType $type, string $localKey): string
    {
        return $connection.':'.$type->value.':'.$localKey;
    }
}

/**
 * Single-process lock token handed out by InMemorySyncStateStore. The
 * token records its connection and released state; release() is
 * idempotent and notifies the store through releaseLock().
 */
final class InMemorySyncLock implements SyncLock
{
    private bool $released = false;

    public function __construct(
        private readonly InMemorySyncStateStore $store,
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

        $this->store->releaseLock($this->connection);
        $this->released = true;
    }

    public function isReleased(): bool
    {
        return $this->released;
    }
}
