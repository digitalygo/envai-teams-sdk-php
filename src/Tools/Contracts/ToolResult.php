<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Contracts;

/**
 * Result of a client tool execution. The output is always a string; when
 * the handler returns structured data it should be serialized once at
 * construction time via json(), so the same encoded representation is
 * sent back to the platform.
 */
final readonly class ToolResult
{
    public function __construct(
        public string $output,
        public bool $isError = false,
    ) {}

    /**
     * Builds a result from a JSON-serializable payload, encoding it once.
     *
     * @param  mixed  $payload  JSON-compatible data to send back to the platform.
     */
    public static function json(mixed $payload): self
    {
        return new self(json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
