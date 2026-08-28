<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Manifest;

use Digitalygo\Envai\Definitions\Agent\AgentDefinition;
use Digitalygo\Envai\Definitions\Team\TeamDefinition;
use Digitalygo\Envai\Definitions\Tool\ToolDefinition;

/**
 * Immutable snapshot of the compiled local definitions, keyed by local
 * key. The hash covers the normalized remote payloads (see
 * CanonicalManifestHasher) and is stable across file order, formatting
 * and newline style; remote ids and local paths never enter it.
 */
final readonly class DefinitionManifest
{
    /**
     * @param  array<string, ToolDefinition>  $tools  Keyed by tool local key.
     * @param  array<string, AgentDefinition>  $agents  Keyed by agent local key.
     * @param  array<string, TeamDefinition>  $teams  Keyed by team local key.
     */
    public function __construct(
        public string $formatVersion = '1',
        public array $tools = [],
        public array $agents = [],
        public array $teams = [],
        public string $hash = '',
    ) {}

    public function agent(string $localKey): AgentDefinition
    {
        if (! isset($this->agents[$localKey])) {
            throw new \InvalidArgumentException(sprintf('No agent definition with local key "%s".', $localKey));
        }

        return $this->agents[$localKey];
    }

    public function team(string $localKey): TeamDefinition
    {
        if (! isset($this->teams[$localKey])) {
            throw new \InvalidArgumentException(sprintf('No team definition with local key "%s".', $localKey));
        }

        return $this->teams[$localKey];
    }

    public function tool(string $localKey): ToolDefinition
    {
        if (! isset($this->tools[$localKey])) {
            throw new \InvalidArgumentException(sprintf('No tool definition with local key "%s".', $localKey));
        }

        return $this->tools[$localKey];
    }
}
