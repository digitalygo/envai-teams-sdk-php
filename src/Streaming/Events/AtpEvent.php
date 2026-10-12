<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * Base class of every event decoded from the ATP stream.
 *
 * `envelope` is the validated event envelope and `extra` holds the unknown
 * payload (data) fields of the event, preserved for forward compatibility.
 * The envelope `raw` array keeps the original decoded wire payload.
 *
 * @param  array<array-key, mixed>  $extra  Unknown payload fields preserved for forward compatibility.
 */
abstract readonly class AtpEvent
{
    /**
     * @param  array<array-key, mixed>  $extra  Unknown payload fields preserved for forward compatibility.
     */
    public function __construct(
        public EventEnvelope $envelope,
        public array $extra = [],
    ) {}

    /**
     * The wire event type of this class (`ping`, `turn.started`, ...).
     * `UnknownEvent` reports "unknown" and `ProtocolViolationEvent` reports
     * "protocol.violation"; the concrete wire type of an instance is always
     * available through `envelope->type`.
     */
    abstract public static function type(): string;

    /**
     * The complete decoded envelope array, including `data`.
     *
     * @return array<string, mixed>
     */
    public function raw(): array
    {
        return $this->envelope->raw;
    }
}
