<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Contracts;

/**
 * Contract implemented by native client tools registered in the local
 * tools.php registry. A client tool belongs to the Envai user and is
 * executed by the SDK runtime when the platform requests it.
 */
interface ClientTool
{
    /**
     * Free text description shown to the LLM.
     */
    public function description(): string;

    /**
     * JSON Schema describing the tool arguments. The schema root must be
     * an object; the compiler normalizes the returned array before it is
     * sent to the platform.
     *
     * @return array<string, mixed>
     */
    public function schema(): array;

    /**
     * Executes the tool for a single invocation and returns the result.
     */
    public function handle(ToolInvocation $invocation): string|ToolResult;
}
