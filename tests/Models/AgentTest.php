<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Models;

use Digitalygo\Envai\Models\Agent\Agent;
use Digitalygo\Envai\Models\Agent\AgentCreateRequest;
use Digitalygo\Envai\Models\Agent\AgentMode;
use Digitalygo\Envai\Models\Agent\AgentTool;
use Digitalygo\Envai\Models\Agent\AgentUpdateRequest;
use Digitalygo\Envai\Models\Common\OptionalField;
use PHPUnit\Framework\TestCase;

final class AgentTest extends TestCase
{
    public function test_agent_hydrates_with_tools_and_preserves_extra(): void
    {
        $agent = Agent::fromArray([
            'id' => 'agt_01j5abcdefghjkmnpqrstvwxyz',
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'researcher',
            'description' => 'Research specialist',
            'system_prompt' => 'You are a research specialist.',
            'model' => 'anthropic/claude-sonnet-4-5',
            'mode' => 'subagent',
            'tools_enabled' => [
                ['name' => 'read_wiki', 'priority' => 10],
                ['name' => 'web_search'],
            ],
            'external_tools_enabled' => [['name' => 'lookup_order']],
            'permissions' => ['network' => false],
            'can_write_knowledge' => true,
            'can_write_skills' => false,
            'config' => ['temperature' => 0.2],
            'created_at' => '2026-08-17T14:00:00+00:00',
            'updated_at' => '2026-08-17T14:00:00+00:00',
            'version' => 3,
        ]);

        $this->assertSame('agt_01j5abcdefghjkmnpqrstvwxyz', $agent->id);
        $this->assertSame(AgentMode::Subagent, $agent->mode);
        $this->assertCount(2, $agent->toolsEnabled);
        $this->assertSame('read_wiki', $agent->toolsEnabled[0]->name);
        $this->assertSame(['priority' => 10], $agent->toolsEnabled[0]->extra);
        $this->assertTrue($agent->canWriteKnowledge);
        $this->assertSame(['temperature' => 0.2], $agent->config);
        $this->assertSame(['version' => 3], $agent->extra);
    }

    public function test_agent_tool_preserves_extra_keys(): void
    {
        $tool = AgentTool::fromArray(['name' => 'read_wiki', 'kind' => 'knowledge', 'description' => 'Read a wiki page']);

        $this->assertSame('read_wiki', $tool->name);
        $this->assertSame(['kind' => 'knowledge', 'description' => 'Read a wiki page'], $tool->extra);
        $this->assertSame(['name' => 'read_wiki', 'kind' => 'knowledge', 'description' => 'Read a wiki page'], $tool->toArray());
    }

    public function test_agent_tool_throws_on_missing_name(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('AgentTool payload is missing required field "name"');

        AgentTool::fromArray(['kind' => 'knowledge']);
    }

    public function test_agent_hydrates_nullable_fields(): void
    {
        $agent = Agent::fromArray([
            'id' => 'agt_01j5abcdefghjkmnpqrstvwxyz',
            'user_id' => 'usr_01j5abcdefghjkmnpqrstvwxyz',
            'name' => 'researcher',
            'description' => null,
            'system_prompt' => 'You are a research specialist.',
            'model' => null,
            'mode' => 'primary',
            'tools_enabled' => [],
            'external_tools_enabled' => [],
            'permissions' => [],
            'can_write_knowledge' => false,
            'can_write_skills' => false,
            'config' => [],
            'created_at' => '2026-08-17T14:00:00+00:00',
            'updated_at' => '2026-08-17T14:00:00+00:00',
        ]);

        $this->assertSame(AgentMode::Primary, $agent->mode);
        $this->assertNull($agent->description);
        $this->assertNull($agent->model);
        $this->assertSame([], $agent->permissions);
        $this->assertFalse($agent->canWriteSkills);
    }

    public function test_agent_create_request_serializes_tools_and_defaults(): void
    {
        $request = new AgentCreateRequest(
            name: 'researcher',
            systemPrompt: 'You are a research specialist.',
            mode: AgentMode::Subagent,
            toolsEnabled: [new AgentTool('read_wiki')],
            canWriteKnowledge: true,
        );

        $this->assertSame([
            'name' => 'researcher',
            'system_prompt' => 'You are a research specialist.',
            'mode' => 'subagent',
            'can_write_knowledge' => true,
            'can_write_skills' => false,
            'tools_enabled' => [['name' => 'read_wiki']],
        ], $request->toArray());
    }

    public function test_agent_update_request_emits_only_present_fields(): void
    {
        $request = AgentUpdateRequest::description(null);

        $this->assertSame(['description' => null], $request->toArray());

        $combined = new AgentUpdateRequest(
            name: OptionalField::of('researcher'),
            description: OptionalField::absent(),
            systemPrompt: OptionalField::absent(),
            model: OptionalField::of('openai/gpt-5-mini'),
            mode: OptionalField::of(AgentMode::Primary),
            toolsEnabled: OptionalField::of([new AgentTool('read_wiki')]),
            externalToolsEnabled: OptionalField::absent(),
            permissions: OptionalField::absent(),
            canWriteKnowledge: OptionalField::absent(),
            canWriteSkills: OptionalField::absent(),
            config: OptionalField::absent(),
        );

        $this->assertSame([
            'name' => 'researcher',
            'model' => 'openai/gpt-5-mini',
            'mode' => 'primary',
            'tools_enabled' => [['name' => 'read_wiki']],
        ], $combined->toArray());
    }
}
