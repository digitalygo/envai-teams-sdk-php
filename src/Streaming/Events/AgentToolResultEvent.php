<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Events;

/**
 * A client tool was resolved: `output` is the client output delivered to the
 * resumed branch, echoed from the tool-output endpoint.
 */
final readonly class AgentToolResultEvent extends AtpEvent
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        EventEnvelope $envelope,
        public string $callId,
        public string $output,
        public bool $isError,
        array $extra = [],
    ) {
        parent::__construct($envelope, $extra);
    }

    public static function type(): string
    {
        return 'agent.tool.result';
    }
}
