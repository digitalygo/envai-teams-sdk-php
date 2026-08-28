<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Definitions\Validation;

use Digitalygo\Envai\Definitions\Agent\AgentDefinition;
use Digitalygo\Envai\Definitions\Agent\AgentMode;
use Digitalygo\Envai\Definitions\Team\TeamBuilder;
use Digitalygo\Envai\Definitions\Team\TeamDefinition;
use Digitalygo\Envai\Definitions\Tool\ToolDefinition;
use Digitalygo\Envai\Definitions\Validation\DefinitionValidator;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Tests\Definitions\DefinitionsTestCase;
use Digitalygo\Envai\Tools\Contracts\ClientTool;

final class DefinitionValidatorTest extends DefinitionsTestCase
{
    public function test_valid_definitions_produce_no_violations(): void
    {
        $violations = (new DefinitionValidator)->validate(
            [$this->agent('orchestrator', AgentMode::Primary), $this->agent('worker', AgentMode::Subagent)],
            [$this->tool('query_db')],
            [$this->team('team', 'orchestrator', ['worker'])],
        );

        $this->assertSame([], $violations);
    }

    public function test_empty_body_is_a_violation(): void
    {
        $agent = new AgentDefinition('agent', 'agent', null, '   ', AgentMode::Subagent);

        $violations = (new DefinitionValidator)->validateAgents([$agent]);

        $this->assertSame('agent.empty_body', $violations[0]->code);
    }

    public function test_missing_remote_name_is_a_violation(): void
    {
        $agent = new AgentDefinition('agent', 'fallback', null, 'Body', AgentMode::Subagent, presentKeys: ['format', 'mode']);

        $violations = (new DefinitionValidator)->validateAgents([$agent]);

        $this->assertSame('agent.name_missing', $violations[0]->code);
    }

    public function test_empty_remote_name_is_a_violation(): void
    {
        $agent = new AgentDefinition('agent', '', null, 'Body', AgentMode::Subagent, presentKeys: ['name']);

        $violations = (new DefinitionValidator)->validateAgents([$agent]);

        $this->assertSame('agent.name_invalid', $violations[0]->code);
    }

    public function test_max_tokens_at_any_nesting_depth_is_a_violation(): void
    {
        $config = new JsonObject([
            'sampling' => new JsonObject([
                'tuning' => new JsonObject([
                    'parameters' => new JsonObject(['max_tokens' => 4096]),
                ]),
            ]),
        ]);

        $agent = new AgentDefinition('agent', 'agent', null, 'Body', AgentMode::Subagent, config: $config);

        $violations = (new DefinitionValidator)->validateAgents([$agent]);

        $this->assertSame('agent.max_tokens_forbidden', $violations[0]->code);
        $this->assertSame('config.max_tokens', $violations[0]->fieldPath);
    }

    public function test_max_tokens_inside_lists_is_a_violation(): void
    {
        $config = new JsonObject([
            'steps' => [
                ['name' => 'first', 'options' => ['max_tokens' => 128]],
            ],
        ]);

        $agent = new AgentDefinition('agent', 'agent', null, 'Body', AgentMode::Subagent, config: $config);

        $violations = (new DefinitionValidator)->validateAgents([$agent]);

        $this->assertSame('agent.max_tokens_forbidden', $violations[0]->code);
    }

    public function test_similar_keys_are_not_mistaken_for_max_tokens(): void
    {
        $config = new JsonObject(['maxTokens' => 10, 'token_limit' => 20, 'max_tokens_per_turn' => 5]);

        $agent = new AgentDefinition('agent', 'agent', null, 'Body', AgentMode::Subagent, config: $config);

        $this->assertSame([], (new DefinitionValidator)->validateAgents([$agent]));
    }

    public function test_tool_name_pattern_is_validated(): void
    {
        $violations = (new DefinitionValidator)->validateTools([$this->tool('BadName')]);

        $this->assertSame('tool.invalid_name', $violations[0]->code);
    }

    public function test_tool_timeout_range_is_validated(): void
    {
        $tool = new ToolDefinition('query_db', ClientTool::class, 'desc', new JsonObject(['type' => 'object']), 0);

        $violations = (new DefinitionValidator)->validateTools([$tool]);

        $this->assertSame('tool.timeout_out_of_range', $violations[0]->code);
    }

    public function test_tool_schema_root_type_is_validated(): void
    {
        $tool = new ToolDefinition('query_db', ClientTool::class, 'desc', new JsonObject(['type' => 'string']), 120);

        $violations = (new DefinitionValidator)->validateTools([$tool]);

        $this->assertSame('tool.schema_root_not_object', $violations[0]->code);
    }

    public function test_tool_schema_json_serializability_is_validated(): void
    {
        $tool = new ToolDefinition('query_db', ClientTool::class, 'desc', new JsonObject(['type' => 'object', 'bad' => "\xC3\x28"]), 120);

        $violations = (new DefinitionValidator)->validateTools([$tool]);

        $this->assertSame('tool.schema_not_serializable', $violations[0]->code);
    }

    public function test_team_orchestrator_must_exist(): void
    {
        $violations = (new DefinitionValidator)->validateTeams(
            [$this->team('team', 'ghost', [])],
            [$this->agent('orchestrator', AgentMode::Primary)],
        );

        $this->assertSame('team.orchestrator_missing', $violations[0]->code);
    }

    public function test_team_orchestrator_must_be_primary(): void
    {
        $violations = (new DefinitionValidator)->validateTeams(
            [$this->team('team', 'worker', ['worker'])],
            [$this->agent('worker', AgentMode::Subagent)],
        );

        $this->assertSame('team.orchestrator_not_primary', $violations[0]->code);
    }

    public function test_team_member_must_exist(): void
    {
        $violations = (new DefinitionValidator)->validateTeams(
            [$this->team('team', 'orchestrator', ['ghost'])],
            [$this->agent('orchestrator', AgentMode::Primary)],
        );

        $this->assertSame('team.member_missing', $violations[0]->code);
    }

    public function test_team_member_must_be_subagent(): void
    {
        $violations = (new DefinitionValidator)->validateTeams(
            [$this->team('team', 'orchestrator', ['other_primary'])],
            [
                $this->agent('orchestrator', AgentMode::Primary),
                $this->agent('other_primary', AgentMode::Primary),
            ],
        );

        $this->assertSame('team.member_not_subagent', $violations[0]->code);
    }

    public function test_team_members_must_be_distinct(): void
    {
        $violations = (new DefinitionValidator)->validateTeams(
            [$this->team('team', 'orchestrator', ['worker', 'worker'])],
            [$this->agent('orchestrator', AgentMode::Primary), $this->agent('worker', AgentMode::Subagent)],
        );

        $this->assertSame('team.member_duplicate', $violations[0]->code);
    }

    public function test_team_orchestrator_must_not_be_a_member(): void
    {
        $violations = (new DefinitionValidator)->validateTeams(
            [$this->team('team', 'orchestrator', ['orchestrator'])],
            [$this->agent('orchestrator', AgentMode::Primary)],
        );

        $this->assertSame('team.orchestrator_is_member', $violations[0]->code);
    }

    public function test_client_tool_references_must_exist_in_the_registry(): void
    {
        $agent = new AgentDefinition(
            'researcher',
            'researcher',
            null,
            'Body',
            AgentMode::Subagent,
            clientTools: ['query_forecasting_database', 'query_other'],
        );

        $violations = (new DefinitionValidator)->validateCrossReferences(
            [$agent],
            [$this->tool('query_forecasting_database')],
        );

        $this->assertCount(1, $violations);
        $this->assertSame('agent.client_tool_missing', $violations[0]->code);
        $this->assertStringContainsString('query_other', $violations[0]->message);
    }

    public function test_server_tool_names_are_opaque_and_not_validated(): void
    {
        $agent = new AgentDefinition(
            'researcher',
            'researcher',
            null,
            'Body',
            AgentMode::Subagent,
            serverTools: ['any_platform_tool'],
        );

        $this->assertSame([], (new DefinitionValidator)->validateCrossReferences([$agent], []));
    }

    public function test_model_must_be_in_the_catalog_when_supplied(): void
    {
        $agent = new AgentDefinition('agent', 'agent', null, 'Body', AgentMode::Subagent, model: 'openai/gpt-5-mini');

        $violations = (new DefinitionValidator)->validateCrossReferences([$agent], [], ['anthropic/claude-sonnet-4-5']);

        $this->assertSame('agent.model_not_in_catalog', $violations[0]->code);

        $this->assertSame([], (new DefinitionValidator)->validateCrossReferences([$agent], [], ['openai/gpt-5-mini']));
    }

    public function test_model_catalog_check_is_skipped_when_catalog_is_null(): void
    {
        $agent = new AgentDefinition('agent', 'agent', null, 'Body', AgentMode::Subagent, model: 'any/model');

        $this->assertSame([], (new DefinitionValidator)->validateCrossReferences([$agent], []));
    }

    public function test_duplicate_remote_names_are_manifest_level_violations(): void
    {
        $agents = [
            $this->agent('first', AgentMode::Subagent, 'shared_name'),
            $this->agent('second', AgentMode::Subagent, 'shared_name'),
        ];

        $violations = (new DefinitionValidator)->validateManifestLevel($agents, [], []);

        $this->assertSame('agent.name_duplicate', $violations[0]->code);
        $this->assertSame('second', $violations[0]->sourceKey);
    }

    public function test_duplicate_local_keys_are_manifest_level_violations(): void
    {
        $violations = (new DefinitionValidator)->validateManifestLevel(
            [
                new AgentDefinition('dup', 'first', null, 'Body', AgentMode::Subagent),
                new AgentDefinition('dup', 'second', null, 'Body', AgentMode::Subagent),
            ],
            [],
            [],
        );

        $this->assertSame('agent.local_key_duplicate', $violations[0]->code);
    }

    public function test_duplicate_team_names_are_manifest_level_violations(): void
    {
        $teams = [
            $this->team('first', 'orchestrator', [], 'shared team'),
            $this->team('second', 'orchestrator', [], 'shared team'),
        ];

        $violations = (new DefinitionValidator)->validateManifestLevel([], [], $teams);

        $this->assertSame('team.name_duplicate', $violations[0]->code);
    }

    public function test_aggregate_validate_runs_all_groups(): void
    {
        $violations = (new DefinitionValidator)->validate(
            [$this->agent('agent', AgentMode::Subagent, 'name')],
            [$this->tool('valid_tool')],
            [$this->team('team', 'missing_orchestrator', [])],
        );

        $codes = array_map(static fn ($violation): string => $violation->code, $violations);

        $this->assertContains('team.orchestrator_missing', $codes);
    }

    private function agent(string $key, AgentMode $mode, ?string $name = null): AgentDefinition
    {
        return new AgentDefinition($key, $name ?? $key, null, 'Body', $mode, presentKeys: ['name']);
    }

    private function tool(string $name): ToolDefinition
    {
        return new ToolDefinition($name, ClientTool::class, 'desc', new JsonObject(['type' => 'object']), 120);
    }

    /**
     * @param  list<string>  $members
     */
    private function team(string $key, string $orchestrator, array $members, string $name = 'Team'): TeamDefinition
    {
        $builder = TeamBuilder::make($key)->name($name)->orchestrator($orchestrator);

        foreach ($members as $member) {
            $builder->member($member);
        }

        return $builder->build();
    }
}
