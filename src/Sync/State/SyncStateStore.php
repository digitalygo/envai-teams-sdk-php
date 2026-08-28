<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\State;

/**
 * Contract of the per-connection sync state store, normative from the
 * project document (section 17.1).
 *
 * The store records, per resource: connection, type, local key, remote
 * id, remote name, desired hash, normalized last-applied payload, remote
 * marker (for example `updated_at`), last synchronization timestamp and
 * lifecycle status. It also records the last manifest marked ready per
 * connection.
 *
 * The key is unique per connection + type + local key. The store must
 * guarantee the lock and the atomic update of a single resource, or
 * explicitly declare a reduced capability.
 */
interface SyncStateStore
{
    /**
     * Acquires the exclusive lock over the connection. Implementations
     * that cannot provide a real mutex may return a single-process lock
     * object, but they must reject a second acquisition while the lock
     * is held.
     */
    public function acquireLock(string $connection): SyncLock;

    /**
     * Reads the state of one managed resource, or null when the
     * connection never managed it.
     */
    public function getResource(
        string $connection,
        ResourceType $type,
        string $localKey,
    ): ?ManagedResourceState;

    /**
     * Yields the states of all resources managed by the connection.
     *
     * @return iterable<ManagedResourceState>
     */
    public function resources(string $connection): iterable;

    /**
     * Creates or replaces the state of one resource (upsert by
     * connection + type + local key).
     */
    public function saveResource(ManagedResourceState $state): void;

    /**
     * Removes the state of one resource from the connection. Deleting an
     * unknown resource is a no-op.
     */
    public function deleteResource(
        string $connection,
        ResourceType $type,
        string $localKey,
    ): void;

    /**
     * Marks the given manifest hash as the last ready manifest for the
     * connection, recording the synchronization timestamp.
     */
    public function markManifestReady(
        string $connection,
        string $manifestHash,
    ): void;

    /**
     * The last manifest marked ready for the connection, or null when
     * the connection was never synchronized.
     */
    public function readyManifest(string $connection): ?ReadyManifest;
}
