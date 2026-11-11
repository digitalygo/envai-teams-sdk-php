<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\State;

/** Optional persistent tombstones; scope the store to one account/deployment. */
interface ToolReplayBarrierStore
{
    /** @param 'call'|'turn'|'task' $kind */
    public function markTerminal(string $chatId, string $kind, string $id): void;

    /** @param 'call'|'turn'|'task' $kind */
    public function isTerminal(string $chatId, string $kind, string $id): bool;
}
