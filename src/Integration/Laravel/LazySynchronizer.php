<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel;

use Digitalygo\Envai\Sync\DefinitionsService;
use Digitalygo\Envai\Sync\State\ReadyManifest;

/**
 * The lazy synchronization gate (project document section 23.2).
 *
 * `lazy` mode is meant for local development: the first high-level
 * operation ensures the local definitions are synchronized with the
 * remote before it proceeds. ensureSynchronized() is that gate:
 *
 * 1. fast check without any lock: when the last manifest marked ready
 *    has the same hash as the freshly compiled definitions and its
 *    timestamp is younger than the TTL, the connection is synchronized
 *    and nothing happens;
 * 2. otherwise the locked critical section runs: apply() acquires the
 *    store lock, re-reads the remote state while planning under the lock
 *    (the re-check: a concurrent process that synchronized first
 *    converges to a no-op plan) and applies with the default options
 *    (no prune, no adopt), marking the manifest ready on full success.
 *
 * The lock is only held for the duration of the apply; failures
 * propagate to the caller unchanged, so a broken remote never silently
 * opens the gate.
 *
 * All modes other than `lazy` are no-ops: `deploy` gates through the
 * explicit CLI flow, `manual` through the low-level API and `off`
 * disables the automatic gate entirely.
 */
final class LazySynchronizer
{
    public function __construct(
        private readonly ?\Closure $nowProvider = null,
    ) {}

    /**
     * Ensures the connection is synchronized when the mode requires it.
     * Throws the underlying plan/apply exception when the sync fails;
     * the gate never swallows errors.
     *
     * @throws \InvalidArgumentException on an unknown mode.
     */
    public function ensureSynchronized(DefinitionsService $definitions, string $mode, int $ttl): void
    {
        $mode = strtolower($mode);

        if (! in_array($mode, ['off', 'manual', 'deploy', 'lazy'], true)) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown sync mode "%s"; supported modes: deploy, lazy, manual, off.',
                $mode,
            ));
        }

        if ($mode !== 'lazy') {
            return;
        }

        if ($this->isSynchronized($definitions, $ttl)) {
            return;
        }

        // Double-checked locking: apply() acquires the store lock,
        // re-checks the remote state while planning inside the critical
        // section and applies with the default SyncOptions (no prune, no
        // adopt). A concurrent process that synced between our fast
        // check and the lock acquisition leaves nothing to do, so its
        // apply is a no-op plan.
        $definitions->apply();
    }

    private function isSynchronized(DefinitionsService $definitions, int $ttl): bool
    {
        $ready = $definitions->readyManifest();

        if ($ready === null) {
            return false;
        }

        if ($ready->manifestHash !== $definitions->manifest()->hash) {
            return false;
        }

        return ! $this->expired($ready, $ttl);
    }

    private function expired(ReadyManifest $ready, int $ttl): bool
    {
        $provider = $this->nowProvider;

        $now = $provider !== null ? $provider() : new \DateTimeImmutable('now');

        if (! $now instanceof \DateTimeImmutable) {
            throw new \InvalidArgumentException('The nowProvider closure must return a DateTimeImmutable.');
        }

        return $now->getTimestamp() - $ready->synchronizedAt->getTimestamp() >= $ttl;
    }
}
