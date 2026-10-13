<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Schema;

use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Streaming\Atp\Payload;
use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tests\Tools\Support\RunnerHarness;
use Digitalygo\Envai\Tools\Execution\ToolArgumentsValidator;
use Digitalygo\Envai\Tools\Execution\ToolRunOutcome;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SchemaValidityTest extends TestCase
{
    #[DataProvider('cases')]
    public function test_entire_schema_is_checked_before_signed_handler_effects(array $schema, JsonObject $arguments, bool $valid): void
    {
        $this->assertSame($valid, ToolArgumentsValidator::validate($arguments, $schema) === []);
        $tool = new FlexibleTool(schema: $schema);
        $harness = new RunnerHarness(['echo' => FlexibleTool::class], [FlexibleTool::class => $tool]);
        $result = $harness->runner->handle($harness->event('call_eager', 'echo', $arguments->data), 'cht_test_1');
        $this->assertSame($valid ? ToolRunOutcome::Executed : ToolRunOutcome::InvalidArguments, $result->outcome);
        $this->assertSame($valid ? 1 : 0, $tool->invocations);
        $this->assertSame(! $valid, $harness->submission('call_eager')['isError']);
    }

    public static function cases(): iterable
    {
        $cases = json_decode(file_get_contents(dirname(__DIR__, 2).'/fixtures/current-server/schema-validity-cases.json'), false, 512, JSON_THROW_ON_ERROR);
        foreach ($cases as $case) {
            yield $case->name => [get_object_vars($case->schema), Payload::typed($case->args), $case->expected];
        }
    }

    #[DataProvider('nonJsonValues')]
    public function test_non_json_values_fail_closed_even_in_annotations(mixed $value): void
    {
        foreach (['default', 'x-extension'] as $keyword) {
            $tool = new FlexibleTool(schema: ['type' => 'object', $keyword => $value]);
            $harness = new RunnerHarness(['echo' => FlexibleTool::class], [FlexibleTool::class => $tool]);
            $result = $harness->runner->handle($harness->event('call_nonjson', 'echo'), 'cht_test_1');
            $this->assertSame(ToolRunOutcome::InvalidArguments, $result->outcome);
            $this->assertSame(0, $tool->invocations);
        }
    }

    public static function nonJsonValues(): array
    {
        return [[NAN], [INF], [-INF], ["\xff"]];
    }

    public function test_all_bundled_metaschemas_are_exact_json_objects(): void
    {
        $root = dirname(__DIR__, 3).'/resources/json-schema/';
        $manifest = json_decode(file_get_contents($root.'manifest.json'), false, 512, JSON_THROW_ON_ERROR);
        $this->assertCount(18, get_object_vars($manifest->schemas));
        foreach ($manifest->schemas as $id => $entry) {
            $bytes = file_get_contents($root.$entry->path);
            $schema = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR);
            $this->assertInstanceOf(\stdClass::class, $schema);
            $this->assertSame($id, $schema->{'$id'});
            $this->assertSame($entry->sha256, hash('sha256', $bytes));
            $this->assertSame($entry->git_blob_sha, sha1('blob '.strlen($bytes)."\0".$bytes));
        }
    }
}
