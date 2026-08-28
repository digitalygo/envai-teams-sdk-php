<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Configuration;

/**
 * Describes how many times a retry-safe request may be attempted and how the
 * delay between attempts grows.
 *
 * Delays grow exponentially from baseDelayMs and are always capped at
 * maxDelayMs. When respectRetryAfter is enabled a server supplied Retry-After
 * hint lifts the delay, still capped at maxDelayMs. Full jitter spreads the
 * delay over [0, delay] to avoid thundering herds; disable jitter for fully
 * deterministic behavior in tests.
 */
final readonly class RetryPolicy
{
    /**
     * @param  list<int>  $retryableStatuses  HTTP statuses that justify a retry.
     */
    public function __construct(
        public int $maxAttempts = 3,
        public int $baseDelayMs = 200,
        public int $maxDelayMs = 10_000,
        public bool $jitter = true,
        public array $retryableStatuses = [429, 502, 503, 504],
        public bool $respectRetryAfter = true,
    ) {
        if ($maxAttempts < 1) {
            throw new \InvalidArgumentException('maxAttempts must be at least 1.');
        }

        if ($baseDelayMs < 0) {
            throw new \InvalidArgumentException('baseDelayMs must not be negative.');
        }

        if ($maxDelayMs < 0) {
            throw new \InvalidArgumentException('maxDelayMs must not be negative.');
        }
    }

    /**
     * Computes the delay before the given attempt (1-based) in milliseconds.
     *
     * @param  float|null  $retryAfterSeconds  Optional Retry-After hint in seconds.
     */
    public function delayMs(int $attempt, ?float $retryAfterSeconds = null): int
    {
        $attempt = max(1, $attempt);

        $delay = (int) min($this->baseDelayMs * (2 ** ($attempt - 1)), $this->maxDelayMs);

        if ($this->respectRetryAfter && $retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            $delay = max($delay, (int) ceil($retryAfterSeconds * 1000));
        }

        $delay = min($delay, $this->maxDelayMs);

        if ($this->jitter && $delay > 0) {
            $delay = random_int(0, $delay);
        }

        return $delay;
    }

    public function isRetryableStatus(int $status): bool
    {
        return in_array($status, $this->retryableStatuses, true);
    }
}
