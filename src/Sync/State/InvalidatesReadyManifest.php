<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\State;

/** Prevents stale readiness surviving a new, partial or failed apply. */
interface InvalidatesReadyManifest
{
    public function clearManifestReady(string $connection): void;
}
