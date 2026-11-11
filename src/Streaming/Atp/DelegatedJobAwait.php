<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Atp;

use Digitalygo\Envai\Contracts\CancellationToken;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Models\Chat\DelegationAccepted;
use Digitalygo\Envai\Models\Chat\TaskSnapshot;
use Digitalygo\Envai\Streaming\Events\AtpEvent;
use Digitalygo\Envai\Tools\Execution\ClientToolRunner;

/** Polls origin status while consuming the owning target's entire live stream. */
final class DelegatedJobAwait
{
    /**
     * @param  iterable<AtpEvent>  $targetEvents
     * @param  callable(): TaskSnapshot  $lookup  Origin GET task; must never return the initial target turn result.
     */
    public static function terminal(
        DelegationAccepted $accepted,
        iterable $targetEvents,
        callable $lookup,
        ?ClientToolRunner $runner = null,
        ?CancellationToken $token = null,
    ): TaskSnapshot {
        $read = static function () use ($lookup, $accepted, $token): TaskSnapshot {
            $token?->throwIfCancelled();
            $task = $lookup();
            $token?->throwIfCancelled();
            if ($task->taskId !== $accepted->taskId || ($task->targetChatId !== null && $task->targetChatId !== $accepted->targetChatId)) {
                throw new ProtocolViolationException('Delegated task identity does not match the receipt.');
            }

            return $task;
        };
        $task = $read();
        if ($task->isTerminal()) {
            return $task;
        }
        foreach ($targetEvents as $event) {
            $token?->throwIfCancelled();
            if ($event->envelope->chatId !== $accepted->targetChatId) {
                throw new ProtocolViolationException('Delegation tool stream belongs to another chat.');
            }
            $runner?->handleBackgroundEvent($event, $accepted->targetChatId);
            $task = $read();
            if ($task->isTerminal()) {
                return $task;
            }
        }
        // A clean EOF can race the final task transition; one final read is safe.
        $task = $read();
        if ($task->isTerminal()) {
            return $task;
        }

        throw new TransportException('Target stream ended before the delegated job settled; outcome is unknown.');
    }
}
