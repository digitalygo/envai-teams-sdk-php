<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\State;

/**
 * Exclusive lock token over one connection held for the duration of a
 * plan or apply phase. The lock is released explicitly (release is
 * idempotent); a released lock reports itself through isReleased().
 *
 * Implementations do not need to be inter-process mutexes: a store may
 * declare a reduced capability, for example a single-process lock object
 * that only guards against concurrent in-process use.
 */
interface SyncLock
{
    /**
     * The connection this lock guards.
     */
    public function connection(): string;

    /**
     * Releases the lock. Calling release more than once has no effect.
     */
    public function release(): void;

    /**
     * Whether the lock has been released.
     */
    public function isReleased(): bool;
}
