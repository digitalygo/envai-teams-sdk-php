<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Execution;

/**
 * Tracks the call ids of the handlers currently executing inside one
 * ClientToolRunner instance.
 *
 * The tracker is a mutable object referenced by the readonly runner so the
 * in-flight set can change without violating the runner's immutability.
 * It is process-local: cross-process admission is the responsibility of the
 * shared ClientToolCallStore.
 */
final class InFlightTracker
{
    /**
     * @var list<string>
     */
    private array $callIds = [];

    public function add(string $callId): void
    {
        $this->callIds[] = $callId;
    }

    public function remove(string $callId): void
    {
        $this->callIds = array_values(array_filter(
            $this->callIds,
            static fn (string $id): bool => $id !== $callId,
        ));
    }

    public function count(): int
    {
        return count($this->callIds);
    }
}
