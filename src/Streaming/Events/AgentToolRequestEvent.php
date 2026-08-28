<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * A client tool request: the platform asks the client to execute a tool.
 *
 * `args` is preserved raw (key order and values intact) so the caller can
 * canonicalize it per RFC 8785 and verify `signature` over
 * `call_id + "\n" + tool_name + "\n" + canonical_json(args)` before
 * executing.
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
