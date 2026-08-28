<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * EdDSA signature block carried by every `agent.tool.request`.
 *
 * The signature covers the canonical payload
 * `call_id + "\n" + tool_name + "\n" + canonical_json(args)` per RFC 8785;
 * the client verifies it with the public key from `/.well-known/jwks.json`
 * matching `kid` before executing the tool. `alg` is always `EdDSA`.
 */
final readonly class ToolSignature
{
    /**
     * @param  array<string, mixed>  $extra  Unknown signature fields preserved for forward compatibility.
     */
    public function __construct(
        public string $alg,
        public string $kid,
        public string $sig,
        public array $extra = [],
    ) {}
}
