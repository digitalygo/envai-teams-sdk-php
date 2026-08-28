<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Diff;

/**
 * Kind of a single path-level difference between two normalized payloads:
 * a key present only in the new payload (added), only in the old payload
 * (removed), or present in both with a different leaf value (changed).
 */
enum DiffKind: string
{
    case Added = 'added';

    case Removed = 'removed';

    case Changed = 'changed';
}
