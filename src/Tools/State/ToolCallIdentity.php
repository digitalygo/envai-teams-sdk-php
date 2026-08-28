<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\State;

/**
 * Identity of a single client tool call, deduplicated by (chatId, callId).
 *
 * `turnId` is the orchestrator completion id the request arrived on, kept as
 * context; it is null when the envelope did not carry one.
 */
final readonly class ToolCallIdentity
{
    public function __construct(
        public string $chatId,
        public string $callId,
        public string $toolName,
        public ?string $turnId = null,
    ) {}
}
