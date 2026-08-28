<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * A valid envelope whose `type` is not part of the known ATP event set.
 *
 * New event types are additive within protocol version 2, so an unknown type
 * is delivered verbatim and must never interrupt the stream. The payload is
 * kept untouched as a JsonObject (key order and values intact) and is also
 * mirrored in the base `extra` property.
 */
final readonly class UnknownEvent extends AtpEvent
{
    public function __construct(
        EventEnvelope $envelope,
        public JsonObject $data,
    ) {
        parent::__construct($envelope, $data->toArray());
    }

    public static function type(): string
    {
        return 'unknown';
    }
}
