<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Contracts;

use Digitalygo\Envai\Http\StreamRequest;

/**
 * Opens a streaming (SSE) connection and returns it for incremental reads.
 */
interface EventStreamTransport
{
    public function open(StreamRequest $request): EventStreamConnection;
}
