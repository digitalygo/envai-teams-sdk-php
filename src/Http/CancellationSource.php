<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http;

use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Exceptions\OperationCancelledException;

/**
 * A mutable CancellationToken owned by the caller. cancel() flips the state;
 * isCancelled() and throwIfCancelled() observe it.
 */
final class CancellationSource implements CancellationToken
{
    private bool $cancelled = false;

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw new OperationCancelledException('The operation was cancelled.');
        }
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function cancelled(): bool
    {
        return $this->isCancelled();
    }
}
