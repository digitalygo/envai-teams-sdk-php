<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Security;

use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Streaming\Events\ToolSignature;

/**
 * A client tool request that passed signature verification.
 *
 * Carries the exact payload the platform signed: `args` keeps the original
 * decoded JSON structure (key order and values intact) so the handler runs
 * against the same arguments the signature covered.
 */
final readonly class VerifiedToolRequest
{
    public function __construct(
        public string $callId,
        public string $toolName,
        public JsonObject $args,
        public ToolSignature $signature,
    ) {}
}
