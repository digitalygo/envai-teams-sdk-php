<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Streaming\Atp;

use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Exceptions\OperationCancelledException;

/**
 * Cooperative cancellation token for tests: cancelled only when the test
 * calls cancel().
 */
final class FakeCancellationToken implements CancellationToken
{
    private bool $cancelled = false;

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    public function throwIfCancelled(): void
    {
        if ($this->cancelled) {
            throw new OperationCancelledException('Cancellation requested.');
        }
    }
}
