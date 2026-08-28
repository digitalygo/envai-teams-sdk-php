<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Definitions\Manifest;

use Digitalygo\Envai\Definitions\Agent\AgentDefinition;
use Digitalygo\Envai\Definitions\Agent\AgentMode;
use Digitalygo\Envai\Definitions\Compiler\DefinitionCompiler;
use Digitalygo\Envai\Definitions\Manifest\CanonicalManifestHasher;
use Digitalygo\Envai\Definitions\Manifest\DefinitionManifest;
use Digitalygo\Envai\Definitions\Team\TeamBuilder;
use Digitalygo\Envai\Definitions\Tool\ToolDefinition;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Tests\Definitions\DefinitionsTestCase;
use Digitalygo\Envai\Tools\Contracts\ClientTool;

final class CanonicalManifestHasherTest extends DefinitionsTestCase
{
    public function test_manifest_hash_is_sha256_prefixed(): void
    {
        $hash = (new CanonicalManifestHasher)->manifestHash($this->manifest());

        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $hash);
    }

    public function test_hash_is_invariant_to_file_order_and_formatting(): void
    {
        $first = (new DefinitionCompiler)->compile($this->layoutA(), catalogModels: null);
        $second = (new DefinitionCompiler)->compile($this->layoutB(), catalogModels: null);

        $this->assertSame($first->manifest->hash, $second->manifest->hash);
        $this->assertSame([], $first->violations);
        $this->assertSame([], $second->violations);
    }

    public function test_hash_is_invariant_to_member_insertion_order_in_the_manifest(): void
    {
        $hasher = new CanonicalManifestHasher;

        $toolA = $this->tool('query_db');
        $toolB = $this->tool('web_search');
        $agent = $this->agent('researcher');

        $forward = new DefinitionManifest(
            '1',
            ['query_db' => $toolA, 'web_search' => $toolB],
            ['researcher' => $agent],
            [],
            '',
        );
        $reversed = new DefinitionManifest(
            '1',
            ['web_search' => $toolB, 'query_db' => $toolA],
            ['researcher' => $agent],
            [],
            '',
        );

        $this->assertSame($hasher->manifestHash($forward), $hasher->manifestHash($reversed));
    }

    public function test_hash_changes_when_the_remote_name_changes(): void
    {
        $hasher = new CanonicalManifestHasher;

        $renamed = new AgentDefinition('researcher', 'researcher_renamed', null, 'Body', AgentMode::Subagent);

        $this->assertNotSame(
            $hasher->manifestHash($this->manifest([$this->agent('researcher')])),
            $hasher->manifestHash($this->manifest([$renamed])),
        );
    }

    public function test_hash_is_invariant_to_crlf_and_lf_newlines(): void
    {
        $compiler = new DefinitionCompiler;

        $lf = $compiler->compile($this->makeEnvaiLayout(agents: [
            'agent' => "---\nformat: 1\nname: agent\nmode: subagent\n---\n\nLine one.\nLine two.\n",
        ]));

        $crlf = $compiler->compile($this->makeEnvaiLayout(agents: [
            'agent' => "---\r\nformat: 1\r\nname: agent\r\nmode: subagent\r\n---\r\n\r\nLine one.\r\nLine two.\r\n",
        ]));

        $this->assertSame($lf->manifest->hash, $crlf->manifest->hash);
    }

    public function test_null_absent_and_empty_are_distinct(): void
    {
        $hasher = new CanonicalManifestHasher;

        $withNullDescription = new AgentDefinition(
            'agent',
            'agent',
            description: null,
            systemPrompt: 'Body',
            mode: AgentMode::Subagent,
            presentKeys: ['name', 'description'],
        );

        $withoutDescription = new AgentDefinition('agent', 'agent', null, 'Body', AgentMode::Subagent, presentKeys: ['name']);

        $withEmptyTools = new AgentDefinition(
            'agent',
            'agent',
            null,
            'Body',
            AgentMode::Subagent,
            serverTools: [],
            presentKeys: ['name', 'tools.server'],
        );

        $withEmptyConfig = new AgentDefinition(
            'agent',
            'agent',
            null,
            'Body',
            AgentMode::Subagent,
            config: new JsonObject([]),
            presentKeys: ['name', 'config'],
        );

        $nullHash = $hasher->manifestHash($this->manifest([$withNullDescription]));
        $absentHash = $hasher->manifestHash($this->manifest([$withoutDescription]));
        $emptyToolsHash = $hasher->manifestHash($this->manifest([$withEmptyTools]));
        $emptyConfigHash = $hasher->manifestHash($this->manifest([$withEmptyConfig]));

        $this->assertNotSame($nullHash, $absentHash);
        $this->assertNotSame($absentHash, $emptyToolsHash);
        $this->assertNotSame($absentHash, $emptyConfigHash);
        $this->assertNotSame($nullHash, $emptyToolsHash);

        // Same construction twice stays identical.
        $this->assertSame($nullHash, $hasher->manifestHash($this->manifest([$withNullDescription])));
    }

    public function test_agent_tool_sets_are_sorted_and_deduplicated_for_the_hash(): void
    {
        $hasher = new CanonicalManifestHasher;

        $first = new AgentDefinition(
            'agent',
            'agent',
            null,
            'Body',
            AgentMode::Subagent,
            serverTools: ['z_tool', 'a_tool', 'a_tool'],
            presentKeys: ['name', 'tools.server'],
        );

        $second = new AgentDefinition(
            'agent',
            'agent',
            null,
            'Body',
            AgentMode::Subagent,
            serverTools: ['a_tool', 'z_tool'],
            presentKeys: ['name', 'tools.server'],
        );

        $this->assertSame(
            $hasher->manifestHash($this->manifest([$first])),
            $hasher->manifestHash($this->manifest([$second])),
        );
    }

    public function test_team_member_order_is_semantically_significant(): void
    {
        $hasher = new CanonicalManifestHasher;

        $ordered = TeamBuilder::make('team')
            ->name('Team')
            ->orchestrator('orchestrator')
            ->member('alpha')
            ->member('zeta')
            ->build();

        $reordered = TeamBuilder::make('team')
            ->name('Team')
            ->orchestrator('orchestrator')
            ->member('zeta')
            ->member('alpha')
            ->build();

        $manifestA = new DefinitionManifest('1', [], [], ['team' => $ordered], '');
        $manifestB = new DefinitionManifest('1', [], [], ['team' => $reordered], '');

        $this->assertNotSame($hasher->manifestHash($manifestA), $hasher->manifestHash($manifestB));
    }

    public function test_team_ids_are_substituted_with_local_keys_in_the_hash(): void
    {
        $team = TeamBuilder::make('team')->name('Team')->orchestrator('orchestrator')->build();
        $manifest = new DefinitionManifest('1', [], [], ['team' => $team], '');

        $hash = (new CanonicalManifestHasher)->manifestHash($manifest);

        $this->assertStringNotContainsString('agt_', $hash);
    }

    public function test_resource_hash_matches_manifest_hash_for_a_single_tool(): void
    {
        $hasher = new CanonicalManifestHasher;
        $tool = $this->tool('query_db');

        $resource = $hasher->resourceHash('tool', 'query_db', $tool->toRemotePayload());
        $manifest = $hasher->manifestHash(new DefinitionManifest('1', ['query_db' => $tool], [], [], ''));

        $this->assertSame($resource, $manifest);
    }

    public function test_resource_hash_for_a_single_agent_without_tools(): void
    {
        $hasher = new CanonicalManifestHasher;
        $agent = $this->agent('researcher');

        $resource = $hasher->resourceHash('agent', 'researcher', $agent->toRemotePayload());
        $manifest = $hasher->manifestHash(new DefinitionManifest('1', [], ['researcher' => $agent], [], ''));

        $this->assertSame($resource, $manifest);
    }

    public function test_resource_hash_rejects_unknown_types(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new CanonicalManifestHasher)->resourceHash('widget', 'x', []);
    }

    public function test_resource_hash_distinguishes_resources_by_local_key(): void
    {
        $hasher = new CanonicalManifestHasher;
        $payload = ['name' => 'agent'];

        $this->assertNotSame(
            $hasher->resourceHash('agent', 'a', $payload),
            $hasher->resourceHash('agent', 'b', $payload),
        );
    }

    public function test_format_version_is_part_of_the_hash(): void
    {
        $agent = $this->agent('researcher');

        $v1 = (new CanonicalManifestHasher)->manifestHash(new DefinitionManifest('1', [], ['researcher' => $agent], [], ''));
        $v2 = (new CanonicalManifestHasher)->manifestHash(new DefinitionManifest('2', [], ['researcher' => $agent], [], ''));

        $this->assertNotSame($v1, $v2);
    }

    private function layoutA(): string
    {
        return $this->makeEnvaiLayout(
            agents: [
                'researcher' => "---\nformat: 1\nname: researcher\nmode: subagent\ntools:\n  server:\n    - exa_search\n  client:\n    - query_db\n---\n\nYou are a researcher.\n",
                'orchestrator' => "---\nformat: 1\nname: orchestrator\nmode: primary\n---\n\nYou orchestrate.\n",
            ],
            teams: [
                'forecasting' => $this->teamPhp('forecasting', "
                    ->name('Forecasting team')
                    ->orchestrator('orchestrator')
                    ->member('researcher')"),
            ],
            tools: "<?php\n\nreturn ['query_db' => ".HashEchoTool::class.'::class];',
        );
    }

    private function layoutB(): string
    {
        return $this->makeEnvaiLayout(
            agents: [
                // Same semantics, different frontmatter key order and YAML formatting.
                'orchestrator' => "---\nname: orchestrator\nmode: primary\nformat: 1\n---\n\nYou orchestrate.\n",
                'researcher' => "---\nmode: subagent\nformat: 1\nname: researcher\ntools:\n  client: [query_db]\n  server: [exa_search]\n---\n\nYou are a researcher.\n",
            ],
            teams: [
                'forecasting' => $this->teamPhp('forecasting', "
                    ->orchestrator('orchestrator')
                    ->name('Forecasting team')
                    ->member('researcher')"),
            ],
            tools: "<?php\n\nreturn ['query_db' => ".HashEchoTool::class.'::class];',
        );
    }

    /**
     * @param  list<AgentDefinition>  $agents
     */
    private function manifest(array $agents = []): DefinitionManifest
    {
        return new DefinitionManifest('1', [], $agents, [], '');
    }

    private function agent(string $key): AgentDefinition
    {
        return new AgentDefinition($key, $key, null, 'Body', AgentMode::Subagent, presentKeys: ['name']);
    }

    private function tool(string $name): ToolDefinition
    {
        return new ToolDefinition($name, ClientTool::class, 'desc', new JsonObject(['type' => 'object']), 120);
    }
}
