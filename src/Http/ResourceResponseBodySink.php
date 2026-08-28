<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http;

use Digitalygo\Envai\Contracts\ResponseBodySink;
use Digitalygo\Envai\Exceptions\TransportException;

/**
 * Writes response chunks to a caller owned PHP resource (stream).
 *
 * complete() flushes buffered data but never closes the resource: its
 * lifecycle stays with the caller.
 */
final class ResourceResponseBodySink implements ResponseBodySink
{
    /**
     * @param  resource  $resource  A writable PHP stream resource.
     */
    public function __construct(private mixed $resource) {}

    public function write(string $chunk): void
    {
        $total = strlen($chunk);
        $written = 0;

        while ($written < $total) {
            $count = @fwrite($this->resource, substr($chunk, $written));

            if ($count === false || $count === 0) {
                throw new TransportException('Failed to write the response chunk to the target resource.');
            }

            $written += $count;
        }
    }

    public function complete(): void
    {
        fflush($this->resource);
    }
}
