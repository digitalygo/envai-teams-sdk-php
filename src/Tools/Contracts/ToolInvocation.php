<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Contracts;

use Digitalygo\Envai\Contracts\CancellationToken;

/**
 * Immutable context of a single tool invocation received from the
 * platform. The workspace is not part of the identity or registration of
 * a tool; it may be carried in the arguments when available.
 */
final readonly class ToolInvocation
{
    /**
     * @param  array<array-key, mixed>  $arguments  Validated arguments; PHP represents integer-looking JSON member names as integer keys.
     */
    public function __construct(
        public string $chatId,
        public string $turnId,
        public string $callId,
        public array $arguments,
        public ?CancellationToken $cancellationToken = null,
    ) {}
}
