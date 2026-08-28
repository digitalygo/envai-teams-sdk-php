<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\State;

/**
 * Immutable record of the last manifest marked ready for a connection.
 *
 * Readiness is scoped to connection/account + manifest hash: when the
 * locally compiled manifest hash differs from this record, high-level
 * helpers that use local definition keys fail closed with
 * DefinitionsNotSynchronizedException.
 *
 * formatVersion is the state record format version of the implementing
 * store, not the definitions format version; metadata is preserved as-is
 * for store-specific bookkeeping.
 */
final readonly class ReadyManifest
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $connection,
        public string $manifestHash,
        public string $formatVersion = '1',
        public \DateTimeImmutable $synchronizedAt = new \DateTimeImmutable,
        public array $metadata = [],
    ) {}
}
