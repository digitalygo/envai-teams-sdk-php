<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\State;

/**
 * Result of a ClientToolCallStore::begin() transition.
 *
 * A `started` status means the call was atomically claimed by this caller;
 * the previous record is null in that case. Any already_* status carries the
 * previous record so the caller can inspect what happened.
 */
final readonly class BeginResult
{
    public function __construct(
        public ToolCallStatus $status,
        public ?ToolCallRecord $previous = null,
    ) {}
}
