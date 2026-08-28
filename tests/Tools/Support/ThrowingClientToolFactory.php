<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Support;

use Digitalygo\Envai\Tools\Contracts\ClientTool;
use Digitalygo\Envai\Tools\Contracts\ClientToolFactory;

/**
 * ClientToolFactory that always throws, used to exercise the runner's
 * handler_error path for uninstantiable handlers.
 */
final class ThrowingClientToolFactory implements ClientToolFactory
{
    public function __construct(
        private readonly \Throwable $exception,
    ) {}

    public function create(string $class): ClientTool
    {
        throw $this->exception;
    }
}
