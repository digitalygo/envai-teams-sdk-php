<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Contracts;

/**
 * Cooperative cancellation signal shared between a caller and a long running
 * operation such as a streaming read.
 */
interface CancellationToken
{
    public function isCancelled(): bool;

    /**
     * Throws when cancellation has been requested.
     */
    public function throwIfCancelled(): void;
}
