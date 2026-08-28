<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Contracts;

/**
 * An open streaming connection over which raw bytes can be read.
 *
 * read() returns null at end of stream, including after cancel() or close().
 * The connection is not thread-safe and must be consumed from a single
 * execution context; the consumer owns the lifecycle and must close() it.
 */
interface EventStreamConnection
{
    /**
     * Reads up to $maxBytes raw bytes from the stream.
     */
    public function read(int $maxBytes = 8192): ?string;

    /**
     * Returns the response status code once available.
     */
    public function statusCode(): int;

    /**
     * Returns the response headers as a map of lowercase name to value list.
     *
     * @return array<string, list<string>>
     */
    public function headers(): array;

    /**
     * Requests cooperative cancellation of the stream.
     */
    public function cancel(): void;

    /**
     * Closes the underlying connection and releases transport resources.
     */
    public function close(): void;

    public function isClosed(): bool;

    public function isCancelled(): bool;
}
