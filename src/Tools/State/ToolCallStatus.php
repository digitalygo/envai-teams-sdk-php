<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\State;

/**
 * Outcome of a ClientToolCallStore::begin() transition as seen by the
 * caller.
 *
 * `started` means the call was atomically transitioned to a fresh running
 * record and side effects may be executed at most once; every already_*
 * status means the caller MUST NOT run the handler again because the call
 * is in flight elsewhere, already resolved or left ambiguous.
 */
enum ToolCallStatus: string
{
    /** The call was transitioned to started; the handler may run. */
    case Started = 'started';

    /** The call is already in flight (started or submitted). */
    case AlreadyRunning = 'already_running';

    /** The call was already resolved; do not run the handler again. */
    case AlreadyResolved = 'already_resolved';

    /** The call was left ambiguous; do not run the handler again. */
    case AlreadyAmbiguous = 'already_ambiguous';
}
