<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * A client tool request: the platform asks the client to execute a tool.
 *
 * `args` is preserved wire-faithfully (key order and values intact) so the
 * caller can canonicalize it per RFC 8785 and verify `signature` over
 * `call_id + "\n" + tool_name + "\n" + canonical_json(args)` before
 * executing.
 *
 * Object semantics are carried by the JsonObject wrapper at depth 0 and by
 * typed JsonObject/JsonArray values at every deeper level (the decoder
 * types the whole tree). This matters for canonicalization: an empty
 * `args` - a request with no arguments at all, which the alpha gateway
 * omits from the wire - must serialize to `{}`, never `[]`. Canonicalize
 * the JsonObject instance itself, never `args->toArray()`, because the
 * plain PHP array cannot distinguish `{}` from `[]` at any depth.
 */
final readonly class AgentToolRequestEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $callId,
        public string $toolName,
        public ToolKind $kind,
        public JsonObject $args,
        public ToolSignature $signature,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'agent.tool.request';
    }
}
