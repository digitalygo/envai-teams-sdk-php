<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Definitions\Agent;

use Digitalygo\Envai\Definitions\Agent\AgentDefinition;
use Digitalygo\Envai\Definitions\Agent\AgentMarkdownCompiler;
use Digitalygo\Envai\Definitions\Agent\AgentMode;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Tests\Definitions\DefinitionsTestCase;

final class AgentMarkdownCompilerTest extends DefinitionsTestCase
{
    public function test_compiles_the_project_document_example(): void
    {
        $path = $this->writeAgentFile('researcher', <<<'MARKDOWN'
        ---
        format: 1
        name: researcher
        description: Research specialist
        mode: subagent
        model: openai/gpt-5-mini

        tools:
          server:
            - exa_search
            - exa_contents
          client:
            - query_forecasting_database

        permissions:
          read: allow
          write: deny

        can_write_knowledge: false
        can_write_skills: false

        config:
          temperature: 0.2
          timeout_seconds: 180
        ---

        You are a research specialist.

        Collect evidence for the assigned task and report uncertainty explicitly.
        MARKDOWN);

        $agent = (new AgentMarkdownCompiler)->compileFile($path);

        $this->assertSame('researcher', $agent->localKey);
        $this->assertSame('researcher', $agent->name);
        $this->assertSame('Research specialist', $agent->description);
        $this->assertSame(AgentMode::Subagent, $agent->mode);
        $this->assertSame('openai/gpt-5-mini', $agent->model);
        $this->assertSame(['exa_search', 'exa_contents'], $agent->serverTools);
        $this->assertSame(['query_forecasting_database'], $agent->clientTools);
        $this->assertSame(['read' => 'allow', 'write' => 'deny'], $agent->permissions->toArray());
        $this->assertFalse($agent->canWriteKnowledge);
        $this->assertFalse($agent->canWriteSkills);
        $this->assertSame(['temperature' => 0.2, 'timeout_seconds' => 180], $agent->config->toArray());
        $this->assertSame([], $agent->extra);
        $this->assertSame(
            "You are a research specialist.\n\nCollect evidence for the assigned task and report uncertainty explicitly.",
            $agent->systemPrompt,
        );
    }

    public function test_remote_payload_maps_frontmatter_to_agent_create_request_shape(): void
    {
        $path = $this->writeAgentFile('researcher', <<<'MARKDOWN'
        ---
        format: 1
        name: researcher
        description: Research specialist
        mode: subagent
        model: openai/gpt-5-mini
        tools:
          server:
            - exa_search
            - exa_contents
          client:
            - query_forecasting_database
        permissions:
          read: allow
          write: deny
        can_write_knowledge: false
        can_write_skills: false
        config:
          temperature: 0.2
          timeout_seconds: 180
        ---

        You are a research specialist.

        Collect evidence for the assigned task and report uncertainty explicitly.
        MARKDOWN);

        $agent = (new AgentMarkdownCompiler)->compileFile($path);

        $this->assertSame([
            'name' => 'researcher',
            'system_prompt' => "You are a research specialist.\n\nCollect evidence for the assigned task and report uncertainty explicitly.",
            'mode' => 'subagent',
            'can_write_knowledge' => false,
            'can_write_skills' => false,
            'description' => 'Research specialist',
            'model' => 'openai/gpt-5-mini',
            'tools_enabled' => [
                ['name' => 'exa_search'],
                ['name' => 'exa_contents'],
            ],
            'external_tools_enabled' => [
                ['name' => 'query_forecasting_database'],
            ],
            'permissions' => ['read' => 'allow', 'write' => 'deny'],
            'config' => ['temperature' => 0.2, 'timeout_seconds' => 180],
        ], $agent->toRemotePayload());
    }

    public function test_tools_are_never_flat_string_arrays_in_the_remote_payload(): void
    {
        $path = $this->writeAgentFile('agent', $this->agentMarkdown(
            frontmatter: "format: 1\nname: agent\nmode: primary\ntools:\n  server:\n    - alpha\n    - beta\n  client:\n    - gamma\n",
        ));

        $payload = (new AgentMarkdownCompiler)->compileFile($path)->toRemotePayload();

        $this->assertSame([['name' => 'alpha'], ['name' => 'beta']], $payload['tools_enabled']);
        $this->assertSame([['name' => 'gamma']], $payload['external_tools_enabled']);
    }

    public function test_body_newlines_are_normalized_from_crlf_and_cr(): void
    {
        $path = $this->writeAgentFile('agent', "---\nformat: 1\nname: agent\nmode: subagent\n---\r\n\r\nLine one.\r\nLine two.\rLast line.\r\n");

        $agent = (new AgentMarkdownCompiler)->compileFile($path);

        $this->assertSame("Line one.\nLine two.\nLast line.", $agent->systemPrompt);
    }

    public function test_body_is_trimmed_to_match_the_platform_normalization(): void
    {
        $path = $this->writeAgentFile('agent', "---\nformat: 1\nname: agent\nmode: subagent\n---\n   \nBody with surrounding whitespace.   \n  ");

        $agent = (new AgentMarkdownCompiler)->compileFile($path);

        // The control plane stores prompts with leading and trailing
        // whitespace stripped, so the compiler trims the body to keep the
        // desired payload identical to the remote and sync drift-free.
        $this->assertSame('Body with surrounding whitespace.', $agent->systemPrompt);
    }

    public function test_unknown_frontmatter_fields_are_preserved_as_extra(): void
    {
        $path = $this->writeAgentFile('agent', $this->agentMarkdown(
            frontmatter: "format: 1\nname: agent\nmode: subagent\npriority: high\nowners:\n  - alice\n",
        ));

        $agent = (new AgentMarkdownCompiler)->compileFile($path);

        $this->assertSame(['priority' => 'high', 'owners' => ['alice']], $agent->extra);
    }

    public function test_explicit_null_description_is_emitted_as_null(): void
    {
        $path = $this->writeAgentFile('agent', $this->agentMarkdown(frontmatter: "format: 1\nname: agent\nmode: subagent\ndescription:\n"));

        $agent = (new AgentMarkdownCompiler)->compileFile($path);

        $this->assertNull($agent->description);
        $this->assertArrayHasKey('description', $agent->toRemotePayload());
        $this->assertNull($agent->toRemotePayload()['description']);
    }

    public function test_absent_description_omits_the_key_from_the_remote_payload(): void
    {
        $path = $this->writeAgentFile('agent', $this->agentMarkdown(frontmatter: "format: 1\nname: agent\nmode: subagent\n"));

        $payload = (new AgentMarkdownCompiler)->compileFile($path)->toRemotePayload();

        $this->assertArrayNotHasKey('description', $payload);
        $this->assertArrayNotHasKey('model', $payload);
        $this->assertArrayNotHasKey('tools_enabled', $payload);
        $this->assertArrayNotHasKey('external_tools_enabled', $payload);
        $this->assertArrayNotHasKey('permissions', $payload);
        $this->assertArrayNotHasKey('config', $payload);
    }

    public function test_explicitly_empty_collections_stay_in_the_remote_payload(): void
    {
        $path = $this->writeAgentFile('agent', $this->agentMarkdown(frontmatter: "format: 1\nname: agent\nmode: subagent\npermissions: {}\ntools:\n  server: []\nconfig: {}\n"));

        $payload = (new AgentMarkdownCompiler)->compileFile($path)->toRemotePayload();

        $this->assertSame([], $payload['permissions']);
        $this->assertSame([], $payload['tools_enabled']);
        $this->assertSame([], $payload['config']);
        $this->assertArrayNotHasKey('external_tools_enabled', $payload);
    }

    public function test_local_key_is_the_filename_without_extension(): void
    {
        $path = $this->writeAgentFile('my_special_agent', $this->agentMarkdown());

        $this->assertSame('my_special_agent', (new AgentMarkdownCompiler)->compileFile($path)->localKey);
    }

    public function test_missing_name_falls_back_to_the_local_key(): void
    {
        $path = $this->writeAgentFile('fallback', $this->agentMarkdown(frontmatter: "format: 1\nmode: subagent\n"));

        $agent = (new AgentMarkdownCompiler)->compileFile($path);

        $this->assertSame('fallback', $agent->name);
        $this->assertFalse($agent->hasFrontmatter('name'));
    }

    public function test_unsupported_format_throws(): void
    {
        $path = $this->writeAgentFile('agent', $this->agentMarkdown(frontmatter: "format: 2\nname: agent\nmode: subagent\n"));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('format must be 1');

        (new AgentMarkdownCompiler)->compileFile($path);
    }

    public function test_missing_format_throws(): void
    {
        $path = $this->writeAgentFile('agent', $this->agentMarkdown(frontmatter: "name: agent\nmode: subagent\n"));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('format must be 1');

        (new AgentMarkdownCompiler)->compileFile($path);
    }

    public function test_invalid_mode_throws(): void
    {
        $path = $this->writeAgentFile('agent', $this->agentMarkdown(frontmatter: "format: 1\nname: agent\nmode: boss\n"));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('mode must be "primary" or "subagent"');

        (new AgentMarkdownCompiler)->compileFile($path);
    }

    public function test_non_string_description_throws(): void
    {
        $path = $this->writeAgentFile('agent', $this->agentMarkdown(frontmatter: "format: 1\nname: agent\nmode: subagent\ndescription: 123\n"));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must be a string');

        (new AgentMarkdownCompiler)->compileFile($path);
    }

    public function test_missing_frontmatter_delimiters_throw(): void
    {
        $path = $this->writeAgentFile('agent', "no frontmatter here\n");

        $this->expectException(\InvalidArgumentException::class);

        (new AgentMarkdownCompiler)->compileFile($path);
    }

    public function test_unterminated_frontmatter_throws(): void
    {
        $path = $this->writeAgentFile('agent', "---\nformat: 1\nname: agent\n");

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unterminated frontmatter');

        (new AgentMarkdownCompiler)->compileFile($path);
    }

    public function test_invalid_yaml_frontmatter_throws(): void
    {
        $path = $this->writeAgentFile('agent', "---\nformat: 1\nname: [unclosed\n---\nbody\n");

        $this->expectException(\InvalidArgumentException::class);

        (new AgentMarkdownCompiler)->compileFile($path);
    }

    public function test_mode_primary_is_supported(): void
    {
        $path = $this->writeAgentFile('orchestrator', $this->agentMarkdown(frontmatter: "format: 1\nname: orchestrator\nmode: primary\n"));

        $this->assertSame(AgentMode::Primary, (new AgentMarkdownCompiler)->compileFile($path)->mode);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public function test_agent_definition_preserves_optional_presence_via_present_keys(): void
    {
        $definition = new AgentDefinition(
            localKey: 'agent',
            name: 'agent',
            description: null,
            systemPrompt: 'Body',
            mode: AgentMode::Subagent,
            model: 'openai/gpt-5-mini',
            permissions: new JsonObject([]),
            config: new JsonObject([]),
            presentKeys: ['name', 'description', 'tools.server'],
        );

        $payload = $definition->toRemotePayload();

        $this->assertArrayHasKey('description', $payload);
        $this->assertNull($payload['description']);
        $this->assertArrayHasKey('tools_enabled', $payload);
        $this->assertSame([], $payload['tools_enabled']);
        $this->assertArrayNotHasKey('model', $payload);
        $this->assertArrayNotHasKey('permissions', $payload);
        $this->assertArrayNotHasKey('config', $payload);
    }

    private function writeAgentFile(string $name, string $content): string
    {
        $directory = $this->makeEnvaiLayout();

        $path = $directory.DIRECTORY_SEPARATOR.'agents'.DIRECTORY_SEPARATOR.$name.'.md';
        file_put_contents($path, $content);

        return $path;
    }
}
