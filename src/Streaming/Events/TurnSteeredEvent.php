<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * Guidance injected into the running turn. `targetAgentId` is absent when
 * the guidance targets the orchestrator.
 */
final readonly class TurnSteeredEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $turnId,
        public string $text,
        public ?string $targetAgentId = null,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'turn.steered';
    }
}
