<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\State;

/**
 * Persisted lifecycle status of a client tool call in a
 * ClientToolCallStore.
 *
 * This is the status stored on ToolCallRecord: the record starts as
 * `started` at begin(), moves to `submitted` when the output delivery is
 * attempted, then to `resolved` on success or `ambiguous` when the outcome
 * cannot be proven (timeout, delivery failure, crash boundary).
 */
enum ClientToolCallStatus: string
{
    /** The call is running; the handler may still execute. */
    case Started = 'started';

    /** The output delivery was attempted but not yet confirmed. */
    case Submitted = 'submitted';

    /** The call has a definitive outcome. */
    case Resolved = 'resolved';

    /** The outcome is unknown and must be reconciled by the application. */
    case Ambiguous = 'ambiguous';
}
