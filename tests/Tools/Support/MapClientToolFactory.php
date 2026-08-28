<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Support;

use Digitalygo\Envai\Tools\Contracts\ClientTool;
use Digitalygo\Envai\Tools\Contracts\ClientToolFactory;

/**
 * ClientToolFactory resolving class names to pre-built test instances.
 */
final class MapClientToolFactory implements ClientToolFactory
{
    /**
     * @param  array<string, ClientTool>  $tools  Keyed by class-string.
     */
    public function __construct(
        private readonly array $tools,
    ) {}

    public function create(string $class): ClientTool
    {
        $tool = $this->tools[$class] ?? null;

        if ($tool === null) {
            throw new \InvalidArgumentException(sprintf('No test instance registered for tool class "%s".', $class));
        }

        return $tool;
    }
}
