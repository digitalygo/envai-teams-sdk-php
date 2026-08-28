<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Diff;

/**
 * One leaf-level difference between two normalized payloads, addressed by
 * a dot-notation path (for example `tools_enabled[0].name`).
 *
 * old carries the previous value and new the incoming one; for added
 * entries old is null, for removed entries new is null, for changed
 * entries both are the leaf values. Array values are kept as-is so the
 * report can serialize them without loss.
 */
final readonly class DiffEntry
{
    public function __construct(
        public string $path,
        public DiffKind $kind,
        public mixed $old = null,
        public mixed $new = null,
    ) {}
}
