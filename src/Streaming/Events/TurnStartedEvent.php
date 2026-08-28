<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * A turn opened: the user message that triggered it is the payload.
 */
final readonly class TurnStartedEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $turnId,
        public string $input,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'turn.started';
    }
}
