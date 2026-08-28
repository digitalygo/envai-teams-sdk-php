<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Definitions\Manifest;

use Digitalygo\Envai\Tools\Contracts\ClientTool;
use Digitalygo\Envai\Tools\Contracts\ToolInvocation;
use Digitalygo\Envai\Tools\Contracts\ToolResult;

/**
 * Minimal ClientTool fixture used inside compiled layouts for the
 * manifest hash tests.
 */
final class HashEchoTool implements ClientTool
{
    public function description(): string
    {
        return 'Echoes input.';
    }

    public function schema(): array
    {
        return ['type' => 'object'];
    }

    public function handle(ToolInvocation $invocation): string|ToolResult
    {
        return new ToolResult('ok');
    }
}
