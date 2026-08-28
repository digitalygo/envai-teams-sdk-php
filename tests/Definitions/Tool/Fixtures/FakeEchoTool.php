<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Definitions\Tool\Fixtures;

use Digitalygo\Envai\Tools\Contracts\ClientTool;
use Digitalygo\Envai\Tools\Contracts\ToolInvocation;
use Digitalygo\Envai\Tools\Contracts\ToolResult;

/**
 * Well-formed ClientTool fixture used by the tool registry tests.
 */
final class FakeEchoTool implements ClientTool
{
    public function description(): string
    {
        return 'Echoes the given text back.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
        ];
    }

    public function handle(ToolInvocation $invocation): string|ToolResult
    {
        return ToolResult::json(['echo' => $invocation->arguments['text'] ?? null]);
    }
}
