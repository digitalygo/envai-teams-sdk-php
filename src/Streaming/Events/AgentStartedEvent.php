<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * An agent instance began streaming its lifecycle.
 */
final readonly class AgentStartedEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $name,
        public string $role,
        public string $model,
        public ?string $parentId,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'agent.started';
    }
}
