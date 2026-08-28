<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Definitions\Tool;

use Digitalygo\Envai\Tools\Contracts\ClientTool;
use Digitalygo\Envai\Tools\Contracts\ToolInvocation;
use Digitalygo\Envai\Tools\Contracts\ToolResult;

/**
 * Fixture whose schema has no root type, exercising the schema
 * normalization that defaults the type to `object`.
 */
final class SchemaWithoutTypeTool implements ClientTool
{
    public function description(): string
    {
        return 'Schema without a root type.';
    }

    public function schema(): array
    {
        return ['properties' => ['text' => ['type' => 'string']]];
    }

    public function handle(ToolInvocation $invocation): string|ToolResult
    {
        return new ToolResult('handled');
    }
}
