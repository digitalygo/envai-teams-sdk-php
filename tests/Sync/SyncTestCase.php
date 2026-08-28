<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync;

use Digitalygo\Envai\Definitions\Agent\AgentDefinition;
use Digitalygo\Envai\Definitions\Agent\AgentMode;
use Digitalygo\Envai\Definitions\Compiler\DefinitionCompilationResult;
use Digitalygo\Envai\Definitions\Manifest\CanonicalManifestHasher;
use Digitalygo\Envai\Definitions\Manifest\DefinitionManifest;
use Digitalygo\Envai\Definitions\Team\TeamDefinition;
use Digitalygo\Envai\Definitions\Team\TeamMemberDefinition;
use Digitalygo\Envai\Definitions\Tool\ToolDefinition;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Tests\Definitions\Tool\Fixtures\FakeEchoTool;
use Digitalygo\Envai\Tools\Contracts\ClientTool;
use PHPUnit\Framework\TestCase;

/**
 * Shared builders for the sync tests: definitions, manifests, remote
 * payloads and compilation results. Manifests are built in memory so
 * tests never touch the filesystem; the DefinitionsService tests use
 * the real compiler against a temporary layout.
 */
abstract class SyncTestCase extends TestCase
{
    protected function tool(string $key, ?string $description = null, JsonObject $parameters = new JsonObject(['type' => 'object', 'properties' => []]), int $timeoutSeconds = 30): ToolDefinition
    {
        return new ToolDefinition(
            localKey: $key,
            handlerClass: FakeEchoTool::class,
            description: $description ?? 'Tool '.$key,
            parameters: $parameters,
            timeoutSeconds: $timeoutSeconds,
        );
    }

    protected function agent(
        string $key,
        ?string $name = null,
        string $systemPrompt = 'You are an agent.',
        AgentMode $mode = AgentMode::Subagent,
        array $serverTools = [],
        array $clientTools = [],
        ?string $description = null,
        ?string $model = null,
    ): AgentDefinition {
        return new AgentDefinition(
            localKey: $key,
            name: $name ?? $key,
            description: $description,
            systemPrompt: $systemPrompt,
            mode: $mode,
            model: $model,
            serverTools: $serverTools,
            clientTools: $clientTools,
        );
    }

    /**
     * @param  list<TeamMemberDefinition>  $members
     */
    protected function team(string $key, string $orchestratorKey, string $name = '', array $members = []): TeamDefinition
    {
        return new TeamDefinition(
            localKey: $key,
            name: $name !== '' ? $name : $key,
            description: null,
            orchestratorKey: $orchestratorKey,
            members: $members,
        );
    }

    protected function member(string $agentKey, ?string $role = null): TeamMemberDefinition
    {
        return new TeamMemberDefinition($agentKey, $role);
    }

    /**
     * @param  array<string, ToolDefinition>  $tools
     * @param  array<string, AgentDefinition>  $agents
     * @param  array<string, TeamDefinition>  $teams
     */
    protected function compiled(array $tools = [], array $agents = [], array $teams = []): DefinitionCompilationResult
    {
        $manifest = $this->manifest($tools, $agents, $teams);

        return new DefinitionCompilationResult($manifest, [], true);
    }

    /**
     * @param  array<string, ToolDefinition>  $tools
     * @param  array<string, AgentDefinition>  $agents
     * @param  array<string, TeamDefinition>  $teams
     */
    protected function manifest(array $tools = [], array $agents = [], array $teams = []): DefinitionManifest
    {
        $manifest = new DefinitionManifest('1', $tools, $agents, $teams, '');

        return new DefinitionManifest('1', $tools, $agents, $teams, (new CanonicalManifestHasher)->manifestHash($manifest));
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function remoteTool(string $name, array $overrides = []): array
    {
        return array_merge([
            'id' => 'twl_'.$name,
            'user_id' => 'usr_test',
            'name' => $name,
            'kind' => 'client',
            'description' => 'Tool '.$name,
            'parameters' => ['type' => 'object', 'properties' => []],
            'timeout_seconds' => 30,
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function remoteAgent(string $name, array $overrides = []): array
    {
        return array_merge([
            'id' => 'agt_'.$name,
            'user_id' => 'usr_test',
            'name' => $name,
            'description' => null,
            'system_prompt' => 'You are an agent.',
            'model' => null,
            'mode' => 'subagent',
            'tools_enabled' => [],
            'external_tools_enabled' => [],
            'permissions' => [],
            'can_write_knowledge' => false,
            'can_write_skills' => false,
            'config' => [],
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ], $overrides);
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function remoteTeam(string $name, string $orchestratorId, array $members = [], array $overrides = []): array
    {
        return array_merge([
            'id' => 'team_'.$name,
            'user_id' => 'usr_test',
            'name' => $name,
            'description' => null,
            'orchestrator_id' => $orchestratorId,
            'members' => $members,
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ], $overrides);
    }

    /**
     * @param  class-string<ClientTool>  $handlerClass
     */
    protected function toolPhp(string $key, string $handlerClass = FakeEchoTool::class): string
    {
        return "<?php\n\nreturn ['".$key."' => ".$handlerClass.'::class];';
    }

    protected function agentMarkdown(string $body = "You are an agent.\n", string $frontmatter = ''): string
    {
        return "---\n".($frontmatter === '' ? "format: 1\nname: agent\nmode: subagent\n" : $frontmatter)."---\n".$body;
    }

    protected function teamPhp(string $localKey, string $body): string
    {
        return <<<PHP
        <?php

        use Digitalygo\Envai\Definitions\Team;

        return Team::make('{$localKey}'){$body};

        PHP;
    }

    /**
     * Creates a temporary envai/ layout with the given agents, teams and
     * optional tools.php content.
     *
     * @param  array<string, string>  $agents
     * @param  array<string, string>  $teams
     */
    protected function makeEnvaiLayout(array $agents = [], array $teams = [], ?string $tools = null): string
    {
        $directory = sys_get_temp_dir().'/atp-sync-'.bin2hex(random_bytes(8));

        mkdir($directory.DIRECTORY_SEPARATOR.'agents', 0777, true);
        mkdir($directory.DIRECTORY_SEPARATOR.'teams', 0777, true);

        foreach ($agents as $name => $content) {
            file_put_contents($directory.DIRECTORY_SEPARATOR.'agents'.DIRECTORY_SEPARATOR.$name.'.md', $content);
        }

        foreach ($teams as $name => $content) {
            file_put_contents($directory.DIRECTORY_SEPARATOR.'teams'.DIRECTORY_SEPARATOR.$name.'.php', $content);
        }

        if ($tools !== null) {
            file_put_contents($directory.DIRECTORY_SEPARATOR.'tools.php', $tools);
        }

        return $directory;
    }
}
