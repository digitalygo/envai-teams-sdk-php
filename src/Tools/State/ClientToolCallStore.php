<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\State;

/**
 * Durable or at least persistable record of client tool call state,
 * indexed by (chatId, callId).
 *
 * begin() atomically transitions a new call to `started`; the handler may
 * run at most once in the limits guaranteed by the configured store.
 * already_* statuses mean the caller MUST NOT run the handler again. The
 * SyncStateStore and the ClientToolCallStore are distinct contracts.
 */
interface ClientToolCallStore
{
    /**
     * Atomically claims a new call or reports its current state.
     */
    public function begin(ToolCallIdentity $identity): BeginResult;

    /**
     * Records that the output delivery was attempted.
     */
    public function markSubmitted(ToolCallIdentity $identity): void;

    /**
     * Records that the call has a definitive outcome.
     */
    public function markResolved(ToolCallIdentity $identity): void;

    /**
     * Records that the outcome could not be proven; the application must
     * reconcile it.
     */
    public function markAmbiguous(ToolCallIdentity $identity): void;
}
