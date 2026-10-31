<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Atp;

use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Models\Chat\TaskSnapshot;
use Digitalygo\Envai\Streaming\Events\AtpEvent;
use Digitalygo\Envai\Streaming\Events\TaskCancelledEvent;
use Digitalygo\Envai\Streaming\Events\TaskCompletedEvent;
use Digitalygo\Envai\Streaming\Events\TaskFailedEvent;

/** Awaits the origin task outcome; a coordinator turn terminal is only an acknowledgment. */
final class TaskAwait
{
    /** @param iterable<AtpEvent> $events */
    public static function terminal(iterable $events, string $chatId, string $taskId): TaskSnapshot
    {
        foreach ($events as $event) {
            if ($event->envelope->chatId !== $chatId) {
                throw new ProtocolViolationException('Task waiter received an event from another chat.');
            }
            if (($event instanceof TaskCompletedEvent || $event instanceof TaskFailedEvent || $event instanceof TaskCancelledEvent) && $event->task->taskId === $taskId) {
                return $event->task;
            }
        }

        throw new TransportException('Task stream ended before a terminal outcome; execution outcome is unknown.');
    }
}
