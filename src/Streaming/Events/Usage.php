<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

use Digitalygo\Envai\Models\Usage\BillingState;
use Digitalygo\Envai\Models\Usage\UsageCost;

/**
 * Usage and cost summary carried by `agent.done`, `agent.tool.complete` and
 * the terminal turn events.
 *
 * The server is authoritative for cost: the SDK never recomputes it from
 * local tariffs. `costUsd`, `durationMs` and `spriteMs` are nullable because
 * v3 can carry unknown costs and additive usage projections; values are never
 * fabricated. Legacy v2 envelopes are separately checked against their complete
 * frozen numeric schema before this DTO is created. Unknown usage fields are preserved in `extra`.
 */
final readonly class Usage
{
    /**
     * @param  array<string, mixed>  $extra  Unknown usage fields preserved for forward compatibility.
     */
    public function __construct(
        public int $inputTokens,
        public int $outputTokens,
        public ?float $costUsd = null,
        public ?int $durationMs = null,
        public ?int $spriteMs = null,
        public array $extra = [],
        public ?int $spriteRamGbMs = null,
        public ?int $toolCalls = null,
        public ?BillingState $billing = null,
        public ?UsageCost $usageCost = null,
    ) {}
}
