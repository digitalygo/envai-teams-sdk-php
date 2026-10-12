<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Execution;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tests\Tools\Support\RunnerHarness;
use Digitalygo\Envai\Tools\Execution\ToolRunOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaObjectListPreservationTest extends TestCase
{
    #[DataProvider('schemas')]
    public function test_signed_calls_preserve_schema_objects_and_lists(array $schema, array $valid, array $invalid): void
    {
        foreach ([[$valid, true], [$invalid, false]] as [$arguments, $accepted]) {
            $tool = new FlexibleTool(schema: $schema);
            $harness = new RunnerHarness(['echo' => FlexibleTool::class], [FlexibleTool::class => $tool]);
            $result = $harness->runner->handle($harness->event('call_shapes', 'echo', $arguments), 'cht_test_1');

            $this->assertSame($accepted ? ToolRunOutcome::Executed : ToolRunOutcome::InvalidArguments, $result->outcome);
            $this->assertSame($accepted ? 1 : 0, $tool->invocations);
            $this->assertSame(! $accepted, $harness->submission('call_shapes')['isError']);
        }
    }

    #[DataProvider('malformedSchemas')]
    public function test_malformed_shapes_still_fail_closed(array $schema): void
    {
        $tool = new FlexibleTool(schema: $schema);
        $harness = new RunnerHarness(['echo' => FlexibleTool::class], [FlexibleTool::class => $tool]);
        $result = $harness->runner->handle($harness->event('call_malformed', 'echo', ['a' => 1]), 'cht_test_1');
        $this->assertSame(ToolRunOutcome::InvalidArguments, $result->outcome);
        $this->assertSame(0, $tool->invocations);
        $this->assertTrue($harness->submission('call_malformed')['isError']);
    }

    public static function malformedSchemas(): array
    {
        return [
            'list where schema is required' => [['additionalProperties' => [['type' => 'string']]]],
            'list nested in schema list' => [['allOf' => [[['type' => 'string']]]]],
            'properties must be a map' => [['properties' => 'invalid']],
            'required dependencies must be lists' => [['dependentRequired' => ['a' => (object) ['0' => 'b']]]],
            'dependency schema malformed required' => [['$schema' => 'http://json-schema.org/draft-07/schema#', 'dependencies' => ['a' => ['required' => new \stdClass]]]],
            'enum must be a list' => [['enum' => ['named' => 1]]],
            'typed list is not a schema' => [['properties' => ['a' => new JsonArray([])]]],
            'unused defs must still be an object map' => [['$defs' => new JsonArray([])]],
            'unused schema list must still be an array' => [['properties' => ['unused' => ['allOf' => new \stdClass]]]],
            'arbitrary PHP objects are not JSON' => [['const' => new \DateTimeImmutable('2026-01-01')]],
        ];
    }

    public static function schemas(): array
    {
        $draft7 = 'http://json-schema.org/draft-07/schema#';
        $object = static fn (array $schema): array => ['type' => 'object', 'properties' => ['value' => $schema], 'required' => ['value']];

        return [
            'dependentRequired' => [
                ['type' => 'object', 'dependentRequired' => ['a' => ['b']]],
                ['a' => 1, 'b' => 2], ['a' => 1],
            ],
            'Draft7 dependencies property list' => [
                ['$schema' => $draft7, 'dependencies' => ['a' => ['b']]],
                ['a' => 1, 'b' => 2], ['a' => 1],
            ],
            'Draft7 dependencies subschema' => [
                ['$schema' => $draft7, 'dependencies' => ['a' => ['required' => ['b'], 'properties' => ['b' => ['type' => 'integer']]]]],
                ['a' => 1, 'b' => 2], ['a' => 1, 'b' => 'wrong'],
            ],
            'Draft7 dependencies mixed map' => [
                ['$schema' => $draft7, 'dependencies' => ['a' => ['b'], 'b' => ['required' => ['c']], 'deny' => false]],
                ['a' => 1, 'b' => 2, 'c' => 3], ['deny' => 1],
            ],
            'object const' => [
                $object(['const' => ['mode' => 'safe', 'nested' => ['ok' => true]]]),
                ['value' => new JsonObject(['mode' => 'safe', 'nested' => new JsonObject(['ok' => true])])],
                ['value' => new JsonObject(['mode' => 'safe', 'nested' => new JsonObject(['ok' => false])])],
            ],
            'object enum' => [
                $object(['enum' => [['mode' => 'read'], ['mode' => 'write']]]),
                ['value' => new JsonObject(['mode' => 'write'])], ['value' => new JsonObject(['mode' => 'delete'])],
            ],
            'const literal keys are not schema keywords' => [
                $object(['const' => ['properties' => [], 'items' => ['const' => []], 'dependentRequired' => ['a' => []]]]),
                ['value' => new JsonObject(['properties' => new JsonArray([]), 'items' => new JsonObject(['const' => new JsonArray([])]), 'dependentRequired' => new JsonObject(['a' => new JsonArray([])])])],
                ['value' => new JsonObject(['properties' => new JsonObject([]), 'items' => new JsonObject(['const' => new JsonArray([])]), 'dependentRequired' => new JsonObject(['a' => new JsonArray([])])])],
            ],
            'enum array containing object' => [
                $object(['enum' => [[['mode' => 'read']]]]),
                ['value' => new JsonArray([new JsonObject(['mode' => 'read'])])], ['value' => new JsonArray([new JsonObject(['mode' => 'write'])])],
            ],
            'contains object const' => [
                $object(['type' => 'array', 'contains' => ['const' => ['ok' => true]]]),
                ['value' => new JsonArray([new JsonObject(['ok' => true])])], ['value' => new JsonArray([new JsonObject(['ok' => false])])],
            ],
            'empty object const is explicit' => [
                $object(['const' => new \stdClass]),
                ['value' => new JsonObject([])], ['value' => new JsonArray([])],
            ],
            'empty array const stays array' => [
                $object(['const' => []]),
                ['value' => new JsonArray([])], ['value' => new JsonObject([])],
            ],
            'enum empty object versus array' => [
                $object(['enum' => [new \stdClass]]),
                ['value' => new JsonObject([])], ['value' => new JsonArray([])],
            ],
            'numeric object const is explicit' => [
                $object(['const' => (object) ['0' => 'zero', '1' => new \stdClass]]),
                ['value' => new JsonObject(['0' => 'zero', '1' => new JsonObject([])])], ['value' => new JsonArray(['zero', new JsonObject([])])],
            ],
            'numeric properties and empty schema' => [
                ['properties' => [0 => ['type' => 'integer'], 1 => []], 'required' => ['0'], 'additionalProperties' => false],
                [0 => 2, 1 => new JsonArray([])], [0 => 'wrong'],
            ],
            'numeric dependentRequired map' => [
                ['dependentRequired' => [0 => ['1']]],
                [0 => 'zero', 1 => 'one'], [0 => 'zero'],
            ],
            'empty dependency maps' => [
                ['dependentRequired' => [], 'dependentSchemas' => [], 'required' => ['ok']],
                ['ok' => true], [],
            ],
            'Draft7 empty dependencies map' => [
                ['$schema' => $draft7, 'dependencies' => [], 'required' => ['ok']],
                ['ok' => true], [],
            ],
            'explicit object subschema recurses' => [
                ['properties' => (object) ['value' => (object) ['dependentRequired' => ['a' => ['b']]]]],
                ['value' => new JsonObject(['a' => 1, 'b' => 2])], ['value' => new JsonObject(['a' => 1])],
            ],
            'typed literal containers' => [
                $object(['const' => new JsonObject(['0' => new JsonArray([new JsonObject([])])])]),
                ['value' => new JsonObject(['0' => new JsonArray([new JsonObject([])])])], ['value' => new JsonObject(['0' => new JsonArray([new JsonArray([])])])],
            ],
            'schema definitions local ref with const' => [
                ['$defs' => ['item' => ['const' => ['ok' => true]]], 'properties' => ['value' => ['$ref' => '#/$defs/item']]],
                ['value' => new JsonObject(['ok' => true])], ['value' => new JsonObject(['ok' => false])],
            ],
            'Draft2019 dependentRequired' => [
                ['$schema' => 'https://json-schema.org/draft/2019-09/schema', 'dependentRequired' => ['a' => ['b']]],
                ['a' => 1, 'b' => 2], ['a' => 1],
            ],
        ];
    }
}
