<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

use Digitalygo\Envai\Models\Json\JsonObject;

/** Chat-level detached activity. The inner tool keeps the actual signed args. */
final readonly class TaskEvent extends AtpEvent
{
    /** @param array<string, mixed> $extra */
    public function __construct(
        EventEnvelope $envelope,
        public string $taskId,
        public string $originTurnId,
        public string $eventType,
        public JsonObject $eventPayload,
        public ?AgentToolRequestEvent $toolRequest = null,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'task.event';
    }
}
