<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

use Digitalygo\Envai\Models\Chat\TaskSnapshot;

final readonly class TaskFailedEvent extends AtpEvent
{
    public function __construct(EventEnvelope $envelope, public TaskSnapshot $task)
    {
        parent::__construct($envelope, $task->extra);
    }

    public static function type(): string
    {
        return 'task.failed';
    }
}
