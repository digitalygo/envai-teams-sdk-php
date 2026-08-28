<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * Usage and cost summary carried by `agent.done`, `agent.tool.complete` and
 * the terminal turn events.
 *
 * The server is authoritative for cost: the SDK never recomputes it from
 * local tariffs. `costUsd`, `durationMs` and `spriteMs` are nullable because
 * the current Envai alpha can emit incomplete usage objects (for example a
 * `chat_usage` without `duration_ms`); missing values stay null and are never
 * fabricated. Unknown usage fields are preserved in `extra`.
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
    ) {}
}
