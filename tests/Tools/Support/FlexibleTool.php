<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Support;

use Digitalygo\Envai\Tools\Contracts\ClientTool;
use Digitalygo\Envai\Tools\Contracts\ToolInvocation;
use Digitalygo\Envai\Tools\Contracts\ToolResult;

/**
 * Configurable ClientTool spy: records invocations and can return a fixed
 * result, sleep, throw or run a re-entrancy hook.
 */
final class FlexibleTool implements ClientTool
{
    public int $invocations = 0;

    /**
     * @var list<ToolInvocation>
     */
    public array $receivedInvocations = [];

    /**
     * Hook run after the optional sleep/throw; assigned after construction
     * so tests can close over a runner built afterwards.
     *
     * @var \Closure(ToolInvocation): void|null
     */
    public ?\Closure $onHandle = null;

    /**
     * @param  array<string, mixed>  $schema
     * @param  string|ToolResult|null  $return  Result returned when no exception is thrown.
     */
    public function __construct(
        private readonly array $schema = ['type' => 'object', 'properties' => [], 'required' => []],
        private readonly string|ToolResult|null $return = 'ok',
        private readonly ?\Throwable $throw = null,
        private readonly int $sleepMicroseconds = 0,
    ) {}

    public function description(): string
    {
        return 'Flexible test tool.';
    }

    public function schema(): array
    {
        return $this->schema;
    }

    public function handle(ToolInvocation $invocation): string|ToolResult
    {
        $this->invocations++;
        $this->receivedInvocations[] = $invocation;

        if ($this->sleepMicroseconds > 0) {
            usleep($this->sleepMicroseconds);
        }

        if ($this->throw !== null) {
            throw $this->throw;
        }

        if ($this->onHandle !== null) {
            ($this->onHandle)($invocation);
        }

        return $this->return ?? 'ok';
    }
}
