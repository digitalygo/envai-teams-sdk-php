<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Contracts;

/**
 * Default ClientToolFactory implementation: instantiates the handler
 * class through reflection. The class must have a constructor without
 * required parameters, otherwise an InvalidArgumentException is thrown.
 *
 * The class is documented as a ClientTool implementation; the compiler
 * validates existence and conformance before calling the factory, so an
 * unknown class surfaces as a ReflectionException here and as a
 * tool.invalid_class violation at the registry level.
 */
final class ReflectionClientToolFactory implements ClientToolFactory
{
    public function create(string $class): ClientTool
    {
        $reflection = new \ReflectionClass($class);
        $constructor = $reflection->getConstructor();

        if ($constructor !== null && $constructor->getNumberOfRequiredParameters() > 0) {
            throw new \InvalidArgumentException(sprintf(
                'Tool handler class "%s" must have a no-argument constructor.',
                $class,
            ));
        }

        return $reflection->newInstance();
    }
}
