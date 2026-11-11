<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http;

use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Exceptions\TransportException;

/** Monotonic total deadline combined with caller-owned cancellation. */
final readonly class DeadlineCancellationToken implements CancellationToken
{
    private float $deadline;

    public function __construct(float $timeoutSeconds, private ?CancellationToken $parent = null)
    {
        if (! is_finite($timeoutSeconds) || $timeoutSeconds <= 0) {
            throw new \InvalidArgumentException('Timeout must be finite and positive.');
        }
        $this->deadline = hrtime(true) / 1e9 + $timeoutSeconds;
    }

    public function isCancelled(): bool
    {
        return ($this->parent?->isCancelled() ?? false) || hrtime(true) / 1e9 >= $this->deadline;
    }

    public function throwIfCancelled(): void
    {
        $this->parent?->throwIfCancelled();
        if (hrtime(true) / 1e9 >= $this->deadline) {
            throw new TransportException('Operation deadline expired; remote outcome may be unknown.');
        }
    }

    public function remainingSeconds(): int
    {
        $this->throwIfCancelled();

        return max(1, (int) ceil($this->deadline - hrtime(true) / 1e9));
    }
}
