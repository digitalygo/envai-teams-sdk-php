<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * A valid envelope with a known `type` whose payload does not validate
 * against the strict event schema.
 *
 * The current Envai alpha can violate the normative schema (for example an
 * interrupted `turn.failed` with an empty usage object). Instead of dropping
 * the event or fabricating values, the decoder delivers this event with the
 * complete list of payload violations and the original payload array
 * untouched. The violated wire type is `envelope->type`.
 */
final readonly class ProtocolViolationEvent extends AtpEvent
{
    /**
     * @param  list<string>  $violations  Every payload violation found, not just the first.
     * @param  array<string, mixed>  $data  The original payload array, untouched.
     */
    public function __construct(
        EventEnvelope $envelope,
        public array $violations,
        public array $data,
    ) {
        parent::__construct($envelope);
    }

    public static function type(): string
    {
        return 'protocol.violation';
    }
}
