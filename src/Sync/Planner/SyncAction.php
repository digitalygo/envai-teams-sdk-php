<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Planner;

/**
 * Action the reconciler should take for an operation. Operations the
 * policy does not authorize carry action None together with a blocking
 * flag or a reason code; the reconciler never touches them.
 */
enum SyncAction: string
{
    case None = 'none';

    case Create = 'create';

    case Update = 'update';

    /**
     * Take an unmanaged remote into management without mutating it; with
     * the overwrite-drift option the reconciler immediately follows with
     * an update mutation in the same apply.
     */
    case Adopt = 'adopt';

    /**
     * Recreate a managed resource whose remote vanished.
     */
    case Recreate = 'recreate';

    case Delete = 'delete';
}
