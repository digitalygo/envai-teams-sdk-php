<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Streaming\Atp;

use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Contracts\EventStreamConnection;

/**
 * Scripted EventStreamConnection for streaming tests.
 *
 * read() pops one queued chunk per call and returns null once the queue is
 * empty. When a wait token is given, an empty queue blocks (polling the
 * token) until the token is cancelled or the connection is closed, then
 * returns null — simulating a long-lived live connection that only ends on
 * cancellation.
 */
final class FakeEventStreamConnection implements EventStreamConnection
{
    /**
     * @var list<string>
     */
    private array $chunks;

    private int $statusCode;

    private bool $cancelled = false;

    private bool $closed = false;

    /**
     * @param  list<string>  $chunks
     */
    public function __construct(array $chunks, int $statusCode = 200, private readonly ?CancellationToken $waitToken = null, private readonly array $responseHeaders = ['content-type' => ['text/event-stream'], 'x-envai-contract-version' => ['3']])
    {
        $this->chunks = $chunks;
        $this->statusCode = $statusCode;
    }

    /**
     * Builds a connection that delivers one SSE frame per queue entry,
     * formatted as `data: <json>\n\n`.
     *
     * @param  list<array<string, mixed>>  $frames
     */
    public static function fromEnvelopes(array $frames, int $statusCode = 200, ?CancellationToken $waitToken = null): self
    {
        $chunks = array_map(
            static fn (array $frame): string => 'data: '.json_encode($frame, JSON_THROW_ON_ERROR)."\n\n",
            $frames,
        );

        return new self($chunks, $statusCode, $waitToken);
    }

    /**
     * Builds a connection over raw byte chunks (fragmentation and multiple
     * frames per chunk are the caller's choice).
     *
     * @param  list<string>  $chunks
     */
    public static function fromChunks(array $chunks, int $statusCode = 200, ?CancellationToken $waitToken = null): self
    {
        return new self($chunks, $statusCode, $waitToken);
    }

    public function read(int $maxBytes = 8192): ?string
    {
        while ($this->chunks === [] && $this->waitToken !== null && ! $this->waitToken->isCancelled() && ! $this->closed) {
            usleep(1000);
        }

        if ($this->chunks === [] || $this->closed) {
            return null;
        }

        return array_shift($this->chunks);
    }

    public function statusCode(): int
    {
        return $this->statusCode;
    }

    public function headers(): array
    {
        return $this->responseHeaders;
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
