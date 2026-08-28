<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Sse;

/**
 * One parsed Server-Sent Events frame.
 *
 * A frame is produced by SseParser when the wire stream reaches the blank
 * line that terminates it. `eventType` is the optional `event:` field value
 * (the last one wins when several `event:` lines accumulate) and `data` is
 * the concatenation of every `data:` line joined with "\n" in arrival order.
 * Comments, `id`, `retry` and unknown fields never appear in a frame.
 */
final readonly class SseFrame
{
    public function __construct(
        public ?string $eventType,
        public string $data,
    ) {}
}
