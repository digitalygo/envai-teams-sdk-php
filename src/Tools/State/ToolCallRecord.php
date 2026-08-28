<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\State;

/**
 * Immutable snapshot of one client tool call in a ClientToolCallStore.
 *
 * `status` is the persisted lifecycle status (started, submitted, resolved
 * or ambiguous); `updatedAt` is the time of the last transition and
 * `outputHash` is reserved for store implementations that fingerprint the
 * delivered output to support reconciliation. The in-memory and file
 * drivers keep `outputHash` null because the store contract does not carry
 * the output payload.
 */
final readonly class ToolCallRecord
{
    public function __construct(
        public ToolCallIdentity $identity,
        public ClientToolCallStatus $status,
        public ?\DateTimeImmutable $updatedAt = null,
        public ?string $outputHash = null,
    ) {}
}
