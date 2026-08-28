<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Compiler;

use Digitalygo\Envai\Definitions\Agent\AgentDefinition;
use Digitalygo\Envai\Definitions\Agent\AgentMarkdownCompiler;
use Digitalygo\Envai\Definitions\Manifest\CanonicalManifestHasher;
use Digitalygo\Envai\Definitions\Manifest\DefinitionManifest;
use Digitalygo\Envai\Definitions\Team\TeamFileCompiler;
use Digitalygo\Envai\Definitions\Tool\ToolRegistryCompiler;
use Digitalygo\Envai\Definitions\Validation\DefinitionValidator;
use Digitalygo\Envai\Definitions\Validation\DefinitionViolation;
use Digitalygo\Envai\Tools\Contracts\ClientToolFactory;
use Digitalygo\Envai\Tools\Contracts\ReflectionClientToolFactory;

/**
 * Orchestrates the compilation of a local definitions directory with the
 * `envai/` layout:
 *
 *     envai/
 *     ├── agents/
 *     │   └── *.md
 *     ├── teams/
 *     │   └── *.php
 *     └── tools.php            (optional; absent means an empty registry)
 *
 * It runs the agent, tool and team compilers, validates the aggregated
 * definitions and builds the manifest with its canonical hash. Content
 * violations are reported as DefinitionViolation entries and never
 * thrown; only structural problems (an explicitly configured path that
 * does not exist, missing agents/teams directories) throw an
 * InvalidArgumentException.
 */
final class DefinitionCompiler
{
    public function __construct(
        private readonly AgentMarkdownCompiler $agentCompiler = new AgentMarkdownCompiler,
        private readonly ToolRegistryCompiler $toolCompiler = new ToolRegistryCompiler,
        private readonly TeamFileCompiler $teamCompiler = new TeamFileCompiler,
        private readonly DefinitionValidator $validator = new DefinitionValidator,
        private readonly CanonicalManifestHasher $hasher = new CanonicalManifestHasher,
    ) {}

    /**
     * @param  list<string>|null  $catalogModels  Model ids available in the catalog; null skips the agent model check.
     */
    public function compile(
        string $definitionsPath,
        ?ClientToolFactory $factory = null,
        ?array $catalogModels = null,
    ): DefinitionCompilationResult {
        if (! is_dir($definitionsPath)) {
            throw new \InvalidArgumentException(sprintf('Definitions directory "%s" does not exist.', $definitionsPath));
        }

        $agentsDirectory = $definitionsPath.DIRECTORY_SEPARATOR.'agents';
        $teamsDirectory = $definitionsPath.DIRECTORY_SEPARATOR.'teams';

        if (! is_dir($agentsDirectory)) {
            throw new \InvalidArgumentException(sprintf('Definitions directory "%s" is missing the agents/ subdirectory.', $definitionsPath));
        }

        if (! is_dir($teamsDirectory)) {
            throw new \InvalidArgumentException(sprintf('Definitions directory "%s" is missing the teams/ subdirectory.', $definitionsPath));
        }

        $violations = [];

        $agents = $this->compileAgents($agentsDirectory, $violations);

        $tools = [];
        $toolsPhp = $definitionsPath.DIRECTORY_SEPARATOR.'tools.php';

        if (is_file($toolsPhp)) {
            $toolResult = $this->toolCompiler->compile($toolsPhp, $factory ?? new ReflectionClientToolFactory);
            $tools = $toolResult['definitions'];
            $violations = [...$violations, ...$toolResult['violations']];
        }

        $teamResult = $this->teamCompiler->compile($teamsDirectory);
        $teams = $teamResult['definitions'];
        $violations = [...$violations, ...$teamResult['violations']];

        $violations = [...$violations, ...$this->validator->validate($agents, $tools, $teams, $catalogModels)];

        $provisional = new DefinitionManifest('1', $tools, $agents, $teams, '');
        $hash = $this->hasher->manifestHash($provisional);

        $manifest = new DefinitionManifest('1', $tools, $agents, $teams, $hash);

        return new DefinitionCompilationResult($manifest, $violations, $violations === []);
    }

    /**
     * @param  list<DefinitionViolation>  $violations
     * @return array<string, AgentDefinition>
     */
    private function compileAgents(string $agentsDirectory, array &$violations): array
    {
        $agents = [];
        $files = glob($agentsDirectory.DIRECTORY_SEPARATOR.'*.md') ?: [];
        sort($files, SORT_STRING);

        foreach ($files as $file) {
            $localKey = basename($file, '.md');

            try {
                $agent = $this->agentCompiler->compileFile($file);
                $agents[$agent->localKey] = $agent;
            } catch (\Throwable $exception) {
                $violations[] = new DefinitionViolation(
                    $localKey,
                    '',
                    'agent.invalid_file',
                    $exception->getMessage(),
                );
            }
        }

        return $agents;
    }
}
