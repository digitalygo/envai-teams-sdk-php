<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Definitions\Tool;

use Digitalygo\Envai\Definitions\Tool\ToolRegistryCompiler;
use Digitalygo\Envai\Tests\Definitions\DefinitionsTestCase;
use Digitalygo\Envai\Tests\Definitions\Tool\Fixtures\FakeEchoTool;
use Digitalygo\Envai\Tests\Definitions\Tool\Fixtures\NonObjectSchemaTool;
use Digitalygo\Envai\Tests\Definitions\Tool\Fixtures\NotATool;
use Digitalygo\Envai\Tests\Definitions\Tool\Fixtures\RequiredArgumentTool;
use Digitalygo\Envai\Tools\Contracts\ReflectionClientToolFactory;

final class ToolRegistryCompilerTest extends DefinitionsTestCase
{
    public function test_compiles_class_form_entries(): void
    {
        $path = $this->writeToolsPhp("return ['echo_tool' => ".FakeEchoTool::class.'::class];');

        $result = (new ToolRegistryCompiler)->compile($path, new ReflectionClientToolFactory);

        $this->assertSame([], $result['violations']);
        $this->assertCount(1, $result['definitions']);

        $tool = $result['definitions']['echo_tool'];

        $this->assertSame('echo_tool', $tool->localKey);
        $this->assertSame(FakeEchoTool::class, $tool->handlerClass);
        $this->assertSame('Echoes the given text back.', $tool->description);
        $this->assertSame(120, $tool->timeoutSeconds);
        $this->assertSame('object', $tool->parameters->get('type'));
        $this->assertSame([
            'name' => 'echo_tool',
            'kind' => 'client',
            'description' => 'Echoes the given text back.',
            'parameters' => [
                'type' => 'object',
                'properties' => ['text' => ['type' => 'string']],
                'required' => ['text'],
            ],
            'timeout_seconds' => 120,
        ], $tool->toRemotePayload());
    }

    public function test_compiles_array_form_entries_with_timeout(): void
    {
        $path = $this->writeToolsPhp("return ['echo_tool' => ['class' => ".FakeEchoTool::class."::class, 'timeout_seconds' => 60]];");

        $result = (new ToolRegistryCompiler)->compile($path, new ReflectionClientToolFactory);

        $this->assertSame([], $result['violations']);
        $this->assertSame(60, $result['definitions']['echo_tool']->timeoutSeconds);
    }

    public function test_timeout_defaults_to_120_and_is_clamped_to_the_1_600_range(): void
    {
        $path = $this->writeToolsPhp(sprintf(
            "return ['default' => %s::class, 'low' => ['class' => %s::class, 'timeout_seconds' => 0], 'high' => ['class' => %s::class, 'timeout_seconds' => 900]];",
            FakeEchoTool::class,
            FakeEchoTool::class,
            FakeEchoTool::class,
        ));

        $result = (new ToolRegistryCompiler)->compile($path, new ReflectionClientToolFactory);

        $this->assertSame([], $result['violations']);
        $this->assertSame(120, $result['definitions']['default']->timeoutSeconds);
        $this->assertSame(1, $result['definitions']['low']->timeoutSeconds);
        $this->assertSame(600, $result['definitions']['high']->timeoutSeconds);
    }

    public function test_schema_without_root_type_is_normalized_to_object(): void
    {
        $path = $this->writeToolsPhp(sprintf("return ['plain' => ['class' => %s::class]];", SchemaWithoutTypeTool::class));

        $result = (new ToolRegistryCompiler)->compile($path, new ReflectionClientToolFactory);

        $this->assertSame([], $result['violations']);
        $this->assertSame('object', $result['definitions']['plain']->parameters->get('type'));
    }

    public function test_missing_class_is_a_violation(): void
    {
        $path = $this->writeToolsPhp("return ['broken' => ['timeout_seconds' => 30]];");

        $result = (new ToolRegistryCompiler)->compile($path, new ReflectionClientToolFactory);

        $this->assertCount(1, $result['violations']);
        $this->assertSame('tool.missing_class', $result['violations'][0]->code);
        $this->assertSame('broken', $result['violations'][0]->sourceKey);
        $this->assertArrayNotHasKey('broken', $result['definitions']);
    }

    public function test_non_existing_class_is_a_violation(): void
    {
        $path = $this->writeToolsPhp("return ['ghost' => 'App\\\\Ai\\\\Tools\\\\GhostTool'];");

        $result = (new ToolRegistryCompiler)->compile($path, new ReflectionClientToolFactory);

        $this->assertSame('tool.invalid_class', $result['violations'][0]->code);
        $this->assertArrayNotHasKey('ghost', $result['definitions']);
    }

    public function test_class_that_does_not_implement_client_tool_is_a_violation(): void
    {
        $path = $this->writeToolsPhp(sprintf("return ['not_a_tool' => %s::class];", NotATool::class));

        $result = (new ToolRegistryCompiler)->compile($path, new ReflectionClientToolFactory);

        $this->assertSame('tool.invalid_class', $result['violations'][0]->code);
        $this->assertArrayNotHasKey('not_a_tool', $result['definitions']);
    }

    public function test_handler_with_required_constructor_is_a_violation(): void
    {
        $path = $this->writeToolsPhp(sprintf("return ['dep' => %s::class];", RequiredArgumentTool::class));

        $result = (new ToolRegistryCompiler)->compile($path, new ReflectionClientToolFactory);

        $this->assertSame('tool.invalid_handler', $result['violations'][0]->code);
        $this->assertArrayNotHasKey('dep', $result['definitions']);
    }

    public function test_schema_with_non_object_root_is_a_violation(): void
    {
        $path = $this->writeToolsPhp(sprintf("return ['bad_schema' => %s::class];", NonObjectSchemaTool::class));

        $result = (new ToolRegistryCompiler)->compile($path, new ReflectionClientToolFactory);

        $this->assertSame('tool.schema_root_not_object', $result['violations'][0]->code);
        $this->assertArrayNotHasKey('bad_schema', $result['definitions']);
    }

    public function test_handler_throwing_during_inspection_is_a_violation(): void
    {
        $path = $this->writeToolsPhp(sprintf("return ['exploding' => %s::class];", ThrowingSchemaTool::class));

        $result = (new ToolRegistryCompiler)->compile($path, new ReflectionClientToolFactory);

        $this->assertSame('tool.invalid_handler', $result['violations'][0]->code);
        $this->assertArrayNotHasKey('exploding', $result['definitions']);
    }

    public function test_invalid_entry_type_is_a_violation(): void
    {
        $path = $this->writeToolsPhp("return ['junk' => 42];");

        $result = (new ToolRegistryCompiler)->compile($path, new ReflectionClientToolFactory);

        $this->assertSame('tool.invalid_entry', $result['violations'][0]->code);
        $this->assertArrayNotHasKey('junk', $result['definitions']);
    }

    public function test_reserved_platform_looking_names_are_not_validated_locally(): void
    {
        $path = $this->writeToolsPhp(sprintf("return ['exa_search' => %s::class];", FakeEchoTool::class));

        $result = (new ToolRegistryCompiler)->compile($path, new ReflectionClientToolFactory);

        $this->assertSame([], $result['violations']);
        $this->assertArrayHasKey('exa_search', $result['definitions']);
    }

    public function test_registry_not_returning_an_array_is_structural(): void
    {
        $path = $this->writeToolsPhp('return "nope";');

        $this->expectException(\InvalidArgumentException::class);

        (new ToolRegistryCompiler)->compile($path, new ReflectionClientToolFactory);
    }

    public function test_missing_registry_file_is_structural(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new ToolRegistryCompiler)->compile('/nonexistent/tools.php', new ReflectionClientToolFactory);
    }

    private function writeToolsPhp(string $content): string
    {
        $directory = $this->makeEnvaiLayout();

        $path = $directory.DIRECTORY_SEPARATOR.'tools.php';
        file_put_contents($path, "<?php\n\n".$content);

        return $path;
    }
}
