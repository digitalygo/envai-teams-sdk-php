<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Reconciler;

/**
 * Outcome of one operation during an apply.
 *
 * - pending: the operation was executable but the apply was a dry run;
 * - skipped: the operation was not executed (blocked, out of scope,
 *   prune disabled, or the apply stopped after a failure);
 * - succeeded: the mutation landed and the state store was updated;
 * - failed: the mutation was rejected (API error) or refused;
 * - ambiguous: a transport failure left the mutation outcome unproven
 *   and the reconciliation read could not prove it (fail-closed).
 */
enum SyncOperationOutcome: string
{
    case Pending = 'pending';

    case Skipped = 'skipped';

    case Succeeded = 'succeeded';

    case Failed = 'failed';

    case Ambiguous = 'ambiguous';
}
