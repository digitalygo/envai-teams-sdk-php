<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Contracts;

/**
 * Receives response body chunks, for example during a streaming download.
 */
interface ResponseBodySink
{
    public function write(string $chunk): void;

    /**
     * Signals that the body is complete; the sink must flush pending data.
     */
    public function complete(): void;
}
