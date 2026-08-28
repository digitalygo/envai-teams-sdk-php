<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * The agent spawned a child agent. `mode` is `foreground` (the parent blocks
 * until the child completes) or `background` (parent and child interleave).
 */
final readonly class AgentDelegatedEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $childInstanceId,
        public string $childName,
        public string $mode,
        public string $title,
        public string $task,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'agent.delegated';
    }
}
