<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Definitions\Tool;

use Digitalygo\Envai\Tools\Contracts\ClientTool;
use Digitalygo\Envai\Tools\Contracts\ToolInvocation;
use Digitalygo\Envai\Tools\Contracts\ToolResult;

/**
 * Fixture whose schema() throws, exercising the compiler's handler
 * inspection error path.
 */
final class ThrowingSchemaTool implements ClientTool
{
    public function description(): string
    {
        return 'Throws while inspecting.';
    }

    public function schema(): array
    {
        throw new \LogicException('schema() exploded');
    }

    public function handle(ToolInvocation $invocation): string|ToolResult
    {
        return new ToolResult('handled');
    }
}
