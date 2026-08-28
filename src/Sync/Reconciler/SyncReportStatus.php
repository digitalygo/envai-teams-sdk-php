<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Reconciler;

/**
 * Overall status of an apply report.
 *
 * - succeeded: every executable operation landed, nothing was blocked or
 *   ambiguous; the manifest is marked ready when the scope is complete;
 * - blocked: the plan contained blocked operations (drift, conflict,
 *   adoption required, missing remote) or an ambiguous outcome stopped
 *   the apply;
 * - partial: the apply was interrupted by an unexpected exception after
 *   at least one success (defensive catch-all, the manifest is never
 *   marked ready);
 * - failed: at least one mutation was rejected with an API error;
 * - dry_run: the apply was a dry run, nothing was mutated.
 */
enum SyncReportStatus: string
{
    case Succeeded = 'succeeded';

    case Blocked = 'blocked';

    case Partial = 'partial';

    case Failed = 'failed';

    case DryRun = 'dry_run';
}
