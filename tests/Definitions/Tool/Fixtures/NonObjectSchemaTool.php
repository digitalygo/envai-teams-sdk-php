<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Definitions\Tool\Fixtures;

use Digitalygo\Envai\Tools\Contracts\ClientTool;
use Digitalygo\Envai\Tools\Contracts\ToolInvocation;
use Digitalygo\Envai\Tools\Contracts\ToolResult;

/**
 * Fixture whose schema root type is not `object`, which the compiler
 * must reject as a violation.
 */
final class NonObjectSchemaTool implements ClientTool
{
    public function description(): string
    {
        return 'Has a non-object schema.';
    }

    public function schema(): array
    {
        return ['type' => 'string', 'minLength' => 1];
    }

    public function handle(ToolInvocation $invocation): string|ToolResult
    {
        return new ToolResult('handled');
    }
}
