<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * A server, knowledge or code tool finished. `result` keeps its structured
 * shape (string, object, array, number, boolean or null) instead of being
 * flattened to a string.
 */
final readonly class AgentToolCompleteEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $callId,
        public JsonObject|JsonArray|string|int|float|bool|null $result,
        public bool $isError,
        public Usage $usage,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'agent.tool.complete';
    }
}
