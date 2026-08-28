<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Contracts;

/**
 * Creates ClientTool instances from their class names. The definitions
 * compiler uses a factory to inspect the description() and schema() of
 * registered tools without coupling the registry to a concrete
 * instantiation strategy.
 */
interface ClientToolFactory
{
    /**
     * @param  class-string<ClientTool>  $class
     */
    public function create(string $class): ClientTool;
}
