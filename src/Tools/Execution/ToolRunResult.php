<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Execution;

use Digitalygo\Envai\Tools\State\ClientToolCallStatus;

/**
 * Result of a single ClientToolRunner::handle() run.
 *
 * `outputSubmitted` reports whether the platform accepted the output (or
 * confirmed it was already present); `storeStatus` is the final lifecycle
 * status of the call in the store and `error` carries a human readable
 * reason when the run failed, never the output payload or credentials.
 */
final readonly class ToolRunResult
{
    public function __construct(
        public string $callId,
        public ToolRunOutcome $outcome,
        public bool $outputSubmitted,
        public ?ClientToolCallStatus $storeStatus,
        public ?string $error = null,
    ) {}
}
