<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Definitions\Tool\Fixtures;

use Digitalygo\Envai\Tools\Contracts\ClientTool;
use Digitalygo\Envai\Tools\Contracts\ToolInvocation;
use Digitalygo\Envai\Tools\Contracts\ToolResult;

/**
 * Fixture whose constructor requires an argument, so the reflection
 * factory must reject it.
 */
final class RequiredArgumentTool implements ClientTool
{
    public function __construct(private readonly string $dependency) {}

    public function description(): string
    {
        return 'Requires a dependency.';
    }

    public function schema(): array
    {
        return ['type' => 'object'];
    }

    public function handle(ToolInvocation $invocation): string|ToolResult
    {
        return new ToolResult('handled');
    }
}
