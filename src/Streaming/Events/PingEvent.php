<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * Wire-only control event emitted periodically to keep proxies and load
 * balancers from closing idle streams. Uses `seq: 0`, has no payload and
 * never advances the stream cursor.
 */
final readonly class PingEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(EventEnvelope $envelope, array $extra = [])
    {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'ping';
    }
}
