<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Contracts\EventStreamConnection;

/**
 * Minimal EventStreamConnection stub for the client service tests.
 */
final class FakeEventStreamConnection implements EventStreamConnection
{
    private bool $closed = false;

    private bool $cancelled = false;

    public function read(int $maxBytes = 8192): ?string
    {
        return null;
    }

    public function statusCode(): int
    {
        return 200;
    }

    /**
     * @return array<string, list<string>>
     */
    public function headers(): array
    {
        return ['content-type' => ['text/event-stream']];
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function close(): void
    {
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled;
    }
}
