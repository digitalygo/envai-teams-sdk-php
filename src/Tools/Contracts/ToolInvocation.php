<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Contracts;

/**
 * Immutable context of a single tool invocation received from the
 * platform. The workspace is not part of the identity or registration of
 * a tool; it may be carried in the arguments when available.
 */
final readonly class ToolInvocation
{
    /**
     * @param  array<string, mixed>  $arguments  Validated arguments of the invocation.
     */
    public function __construct(
        public string $chatId,
        public string $turnId,
        public string $callId,
        public array $arguments,
    ) {}
}
