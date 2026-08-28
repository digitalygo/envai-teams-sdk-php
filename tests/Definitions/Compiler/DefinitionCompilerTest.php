<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Definitions\Compiler;

use Digitalygo\Envai\Definitions\Agent\AgentMode;
use Digitalygo\Envai\Definitions\Compiler\DefinitionCompiler;
use Digitalygo\Envai\Definitions\Manifest\DefinitionManifest;
use Digitalygo\Envai\Tests\Definitions\DefinitionsTestCase;
use Digitalygo\Envai\Tests\Definitions\Manifest\HashEchoTool;

final class DefinitionCompilerTest extends DefinitionsTestCase
{
    public function test_compiles_a_full_valid_layout(): void
    {
        $path = $this->makeEnvaiLayout(
            agents: [
                'orchestrator' => $this->agentMarkdown('You orchestrate.', "format: 1\nname: orchestrator\nmode: primary\n"),
                'researcher' => $this->agentMarkdown(
                    'You are a researcher.',
                    "format: 1\nname: researcher\nmode: subagent\ntools:\n  client:\n    - query_db\n",
                ),
            ],
            teams: [
                'forecasting' => $this->teamPhp('forecasting', "
                    ->name('Forecasting team')
                    ->orchestrator('orchestrator')
                    ->member('researcher')"),
            ],
            tools: "<?php\n\nreturn ['query_db' => ".HashEchoTool::class.'::class];',
        );

        $result = (new DefinitionCompiler)->compile($path);

        $this->assertTrue($result->valid);
        $this->assertSame([], $result->violations);

        $manifest = $result->manifest;

        $this->assertInstanceOf(DefinitionManifest::class, $manifest);
        $this->assertSame('1', $manifest->formatVersion);
        $this->assertSame(AgentMode::Primary, $manifest->agent('orchestrator')->mode);
        $this->assertSame('query_db', $manifest->tool('query_db')->localKey);
        $this->assertSame('forecasting', $manifest->team('forecasting')->localKey);
        $this->assertMatchesRegularExpression('/^sha256:[0-9a-f]{64}$/', $manifest->hash);
    }

    public function test_absent_tools_php_means_an_empty_registry(): void
    {
        $path = $this->makeEnvaiLayout(
            agents: ['agent' => $this->agentMarkdown()],
            teams: [],
        );

        $result = (new DefinitionCompiler)->compile($path);

        $this->assertTrue($result->valid);
        $this->assertSame([], $result->manifest->tools);
    }

    public function test_manifest_is_built_even_with_violations_and_valid_is_false(): void
    {
        $path = $this->makeEnvaiLayout(
            agents: ['agent' => $this->agentMarkdown(frontmatter: "format: 1\nmode: subagent\n")],
            teams: [],
        );

        $result = (new DefinitionCompiler)->compile($path);

        $this->assertFalse($result->valid);
        $this->assertNotSame([], $result->violations);
        $this->assertSame('agent.name_missing', $result->violations[0]->code);
        $this->assertInstanceOf(DefinitionManifest::class, $result->manifest);
        $this->assertSame('agent', $result->manifest->agent('agent')->localKey);
    }

    public function test_content_violations_never_throw(): void
    {
        $path = $this->makeEnvaiLayout(
            agents: [
                'bad_format' => $this->agentMarkdown(frontmatter: "format: 2\nname: bad\nmode: subagent\n"),
                'bad_mode' => $this->agentMarkdown(frontmatter: "format: 1\nname: bad\nmode: whatever\n"),
                'empty_body' => $this->agentMarkdown("   \n"),
                'missing_name' => $this->agentMarkdown(frontmatter: "format: 1\nmode: subagent\n"),
            ],
            teams: ['broken' => "<?php\n\nreturn 42;\n"],
            tools: "<?php\n\nreturn ['broken' => ['timeout_seconds' => 5]];",
        );

        $result = (new DefinitionCompiler)->compile($path);

        $this->assertFalse($result->valid);

        $codes = array_map(static fn ($violation): string => $violation->code, $result->violations);

        $this->assertContains('agent.invalid_file', $codes);
        $this->assertContains('agent.empty_body', $codes);
        $this->assertContains('agent.name_missing', $codes);
        $this->assertContains('team.invalid_file', $codes);
        $this->assertContains('tool.missing_class', $codes);

        // Well-formed resources are still compiled into the manifest.
        $this->assertArrayHasKey('missing_name', $result->manifest->agents);
        $this->assertArrayNotHasKey('bad_format', $result->manifest->agents);
    }

    public function test_catalog_models_are_validated_when_supplied(): void
    {
        $path = $this->makeEnvaiLayout(
            agents: ['agent' => $this->agentMarkdown(
                'Body',
                "format: 1\nname: agent\nmode: subagent\nmodel: openai/gpt-5-mini\n",
            )],
            teams: [],
        );

        $result = (new DefinitionCompiler)->compile($path, catalogModels: ['anthropic/claude-sonnet-4-5']);

        $this->assertFalse($result->valid);
        $this->assertSame('agent.model_not_in_catalog', $result->violations[0]->code);

        $valid = (new DefinitionCompiler)->compile($path, catalogModels: ['openai/gpt-5-mini']);

        $this->assertTrue($valid->valid);
    }

    public function test_client_tool_reference_missing_from_registry_is_a_violation(): void
    {
        $path = $this->makeEnvaiLayout(
            agents: ['agent' => $this->agentMarkdown(
                'Body',
                "format: 1\nname: agent\nmode: subagent\ntools:\n  client:\n    - ghost_tool\n",
            )],
            teams: [],
        );

        $result = (new DefinitionCompiler)->compile($path);

        $this->assertFalse($result->valid);
        $this->assertSame('agent.client_tool_missing', $result->violations[0]->code);
    }

    public function test_missing_definitions_directory_is_structural(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('does not exist');

        (new DefinitionCompiler)->compile('/nonexistent/envai-path');
    }

    public function test_missing_agents_subdirectory_is_structural(): void
    {
        $directory = sys_get_temp_dir().'/atp-definitions-'.bin2hex(random_bytes(8));
        mkdir($directory.DIRECTORY_SEPARATOR.'teams', 0777, true);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('agents/');

            (new DefinitionCompiler)->compile($directory);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_missing_teams_subdirectory_is_structural(): void
    {
        $directory = sys_get_temp_dir().'/atp-definitions-'.bin2hex(random_bytes(8));
        mkdir($directory.DIRECTORY_SEPARATOR.'agents', 0777, true);

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('teams/');

            (new DefinitionCompiler)->compile($directory);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_manifest_accessors_throw_for_unknown_keys(): void
    {
        $path = $this->makeEnvaiLayout(agents: ['agent' => $this->agentMarkdown()], teams: []);

        $manifest = (new DefinitionCompiler)->compile($path)->manifest;

        $this->expectException(\InvalidArgumentException::class);

        $manifest->agent('unknown');
    }

    private function removeDirectory(string $directory): void
    {
        $items = scandir($directory);

        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            @unlink($directory.DIRECTORY_SEPARATOR.$item);
        }

        @rmdir($directory);
    }
}
