<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * A server, knowledge or code tool started executing. `args` is preserved
 * as a raw JsonObject (key order and values intact).
 */
final readonly class AgentToolStartEvent extends AtpEvent
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
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'agent.tool.start';
    }
}
