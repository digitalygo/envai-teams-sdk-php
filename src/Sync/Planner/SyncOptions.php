<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Planner;

/**
 * Policy overrides for planning and applying. The defaults fail closed:
 * drift, conflict, missing remote and unmanaged name collisions block the
 * plan, prune is off. Every destructive override must be explicit; none
 * is activated implicitly in non-interactive environments.
 */
final readonly class SyncOptions
{
    public function __construct(
        public SyncScope $scope = SyncScope::All,
        public bool $adopt = false,
        public bool $overwriteDrift = false,
        public bool $recreateMissing = false,
        public bool $prune = false,
        public bool $failOnDrift = false,
        public bool $dryRun = false,
    ) {}
}
