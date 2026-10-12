<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Security;

use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Streaming\Events\ToolSignature;

/**
 * A client tool request that passed signature verification.
 *
 * Carries an owned argument tree decoded from the verified RFC 8785
 * canonical JSON. Object/list distinctions are preserved, member order is
 * canonical, and binary64-equivalent number spellings produce the same
 * handler values. Original wire data stays on the unverified event.
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
