<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Execution;

use Digitalygo\Envai\Tools\Contracts\ClientTool;

/**
 * A single entry of the client tool registry: the handler class plus the
 * execution timeout in seconds.
 */
final readonly class RegisteredTool
{
    /**
     * @param  class-string<ClientTool>  $class
     */
    public function __construct(
        public string $name,
        public string $class,
        public int $timeoutSeconds,
    ) {}
}
