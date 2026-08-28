<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

/**
 * Payload to resolve a pending client tool call (`agent.tool.request`).
 * `output` is the client output delivered to the resumed branch and is
 * limited to 128 KiB by the gateway; `is_error` defaults to false.
 */
final readonly class ToolOutputRequest
{
    public function __construct(
        public string $callId,
        public string $output,
        public bool $isError = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'call_id' => $this->callId,
            'output' => $this->output,
            'is_error' => $this->isError,
        ];
    }
}
