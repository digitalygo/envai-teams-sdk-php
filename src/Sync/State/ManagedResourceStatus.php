<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\State;

/**
 * Lifecycle status of a managed resource recorded in the state store.
 *
 * - applied: last known mutation landed and the stored last-applied
 *   payload reflects it;
 * - drifted: the remote changed after the last apply and the policy
 *   failed closed;
 * - conflicted: desired and remote both differ from the last applied
 *   state;
 * - missing: the remote is absent although the resource is managed;
 * - ambiguous: a transport failure left the outcome of a mutation
 *   unproven (fail-closed, the manifest is never marked ready).
 */
enum ManagedResourceStatus: string
{
    case Applied = 'applied';

    case Drifted = 'drifted';

    case Conflicted = 'conflicted';

    case Missing = 'missing';

    case Ambiguous = 'ambiguous';
}
