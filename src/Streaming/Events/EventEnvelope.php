<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * Envelope wrapping every event on the ATP stream.
 *
 * Positive `seq` values order replayable events while the chat runtime
 * remains resident; the wire-only `ping` uses `seq: 0`. `turnId` is the
 * orchestrator completion id of the turn the event belongs to, null for
 * `ping`. `agent` is null for `turn.*`, `ping` and `approval.response`.
 *
 * Unknown top-level envelope fields are preserved in `extra`, and `raw`
 * keeps the complete decoded envelope array (including `data`) so callers
 * can always re-serialize or inspect the exact wire payload.
 *
 * @param  array<string, mixed>  $extra  Unknown top-level envelope fields preserved for forward compatibility.
 * @param  array<string, mixed>  $raw  The complete decoded envelope array, including `data`.
 */
final readonly class EventEnvelope
{
    /**
     * @param  array<string, mixed>  $extra  Unknown top-level envelope fields preserved for forward compatibility.
     * @param  array<string, mixed>  $raw  The complete decoded envelope array, including `data`.
     */
    public function __construct(
        public int $v,
        public string $id,
        public int $seq,
        public string $ts,
        public string $chatId,
        public ?string $turnId,
        public ?AgentInstance $agent,
        public string $type,
        public array $extra = [],
        public array $raw = [],
    ) {}
}
