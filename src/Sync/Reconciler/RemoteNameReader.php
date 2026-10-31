<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Reconciler;

/** Optional authoritative unique-name lookup after a lost create response. */
interface RemoteNameReader
{
    /** @return array<string, mixed>|null Null includes absent or ambiguous names. */
    public function readRemoteByName(string $type, string $name): ?array;
}
