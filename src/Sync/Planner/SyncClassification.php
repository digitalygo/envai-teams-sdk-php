<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Planner;

/**
 * Three-way classification comparing desired, last applied and remote state:
 *
 * - unchanged: all three agree;
 * - update: desired changed, remote still equals the last apply;
 * - drift: remote changed after the last apply, desired untouched;
 * - conflict: both desired and remote differ from the last apply;
 * - create: the resource is declared but never applied and has no remote;
 * - adoption_required: a remote with the same name exists unmanaged;
 * - missing_remote: the managed remote vanished;
 * - orphan_managed: the remote is managed but no longer declared.
 */
enum SyncClassification: string
{
    case Unchanged = 'unchanged';

    case Create = 'create';

    case Update = 'update';

    case Drift = 'drift';

    case Conflict = 'conflict';

    case AdoptionRequired = 'adoption_required';

    case MissingRemote = 'missing_remote';

    case OrphanManaged = 'orphan_managed';
}
