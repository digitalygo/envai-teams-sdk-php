<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client\Internal;

use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Exceptions\TransportException;

/** Read-only polling with one total deadline, including each HTTP read. */
final class Poller
{
    /**
     * @template T
     *
     * @param  \Closure(int): T  $read  Receives the remaining whole-second HTTP timeout.
     * @param  \Closure(T): bool  $done
     * @return T
     */
    public static function wait(\Closure $read, \Closure $done, float $timeoutSeconds, int $intervalMilliseconds, ?CancellationToken $token): mixed
    {
        if (! is_finite($timeoutSeconds) || $timeoutSeconds <= 0 || $intervalMilliseconds < 1) {
            throw new \InvalidArgumentException('Polling bounds must be positive.');
        }
        $deadline = hrtime(true) / 1e9 + $timeoutSeconds;
        while (true) {
            $token?->throwIfCancelled();
            $remaining = $deadline - hrtime(true) / 1e9;
            if ($remaining <= 0) {
                throw new TransportException('Polling timed out.');
            }
            $result = $read(max(1, (int) ceil($remaining)));
            // Cancellation/deadline wins even when the read returns terminal.
            $token?->throwIfCancelled();
            if (hrtime(true) / 1e9 >= $deadline) {
                throw new TransportException('Polling timed out.');
            }
            if ($done($result)) {
                return $result;
            }
            $until = min($deadline, hrtime(true) / 1e9 + $intervalMilliseconds / 1000);
            while (($left = $until - hrtime(true) / 1e9) > 0) {
                $token?->throwIfCancelled();
                usleep((int) min(100000, max(1, $left * 1e6)));
            }
        }
    }
}
