<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Execution;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tests\Tools\Support\RunnerHarness;
use Digitalygo\Envai\Tools\Execution\RunnerOptions;
use Digitalygo\Envai\Tools\Execution\ToolRunOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CompleteSchemaValidationTest extends TestCase
{
    #[DataProvider('invalidArguments')]
    public function test_complete_schema_rejects_arguments_before_handler_effects(array $schema, array $arguments): void
    {
        $tool = new FlexibleTool(schema: $schema);
        $harness = new RunnerHarness(['echo' => FlexibleTool::class], [FlexibleTool::class => $tool]);
        $result = $harness->runner->handle($harness->event('call_schema', 'echo', $arguments), 'cht_test_1');
        $this->assertSame(ToolRunOutcome::InvalidArguments, $result->outcome);
        $this->assertSame(0, $tool->invocations);
        $this->assertTrue($harness->submission('call_schema')['isError']);
    }

    public static function invalidArguments(): array
    {
        return [
            'nested required' => [['type' => 'object', 'properties' => ['settings' => ['type' => 'object', 'required' => ['region']]]], ['settings' => new JsonObject([])]],
            'nested type' => [['type' => 'object', 'properties' => ['settings' => ['type' => 'object', 'properties' => ['region' => ['type' => 'string']]]]], ['settings' => new JsonObject(['region' => 3])]],
            'enum' => [['type' => 'object', 'properties' => ['mode' => ['enum' => ['read']]]], ['mode' => 'write']],
            'additional properties' => [['type' => 'object', 'properties' => [], 'additionalProperties' => false], ['admin' => true]],
            'array item' => [['type' => 'object', 'properties' => ['values' => ['type' => 'array', 'items' => ['type' => 'integer']]]], ['values' => new JsonArray([1, 'bad'])]],
            'object is not array' => [['type' => 'object', 'properties' => ['values' => ['type' => 'array']]], ['values' => new JsonObject([])]],
            'array is not object' => [['type' => 'object', 'properties' => ['values' => ['type' => 'object']]], ['values' => new JsonArray([])]],
            'external ref fails closed' => [['type' => 'object', 'properties' => ['data' => ['$ref' => 'https://example.invalid/schema.json']]], ['data' => 1]],
        ];
    }

    public function test_empty_object_is_valid_before_native_conversion(): void
    {
        $tool = new FlexibleTool(schema: ['type' => 'object', 'properties' => ['settings' => ['type' => 'object', 'additionalProperties' => false]]]);
        $harness = new RunnerHarness(['echo' => FlexibleTool::class], [FlexibleTool::class => $tool]);
        $result = $harness->runner->handle($harness->event('call_empty', 'echo', ['settings' => new JsonObject([])]), 'cht_test_1');
        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertSame(['settings' => []], $tool->receivedInvocations[0]->arguments);
    }

    public function test_empty_handler_output_is_replaced_by_controlled_error(): void
    {
        $tool = new FlexibleTool(return: '');
        $harness = new RunnerHarness(['echo' => FlexibleTool::class], [FlexibleTool::class => $tool]);
        $result = $harness->runner->handle($harness->event('call_empty_output', 'echo'), 'cht_test_1');
        $this->assertSame(ToolRunOutcome::EmptyOutput, $result->outcome);
        $this->assertTrue($result->outputSubmitted);
        $submission = $harness->submission('call_empty_output');
        $this->assertTrue($submission['isError']);
        $this->assertSame('empty_output', json_decode($submission['output'], true, 512, JSON_THROW_ON_ERROR)['code']);
    }

    public function test_nonfinite_deadline_margin_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RunnerOptions(submissionMarginSeconds: NAN);
    }
}
