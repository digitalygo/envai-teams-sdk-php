<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Execution;

use Digitalygo\Envai\Models\Json\CanonicalJsonSerializer;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Streaming\Atp\AtpEventDecoder;
use Digitalygo\Envai\Streaming\Events\AgentToolRequestEvent;
use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tests\Tools\Support\RunnerHarness;
use Digitalygo\Envai\Tests\Tools\Support\TestKeys;
use Digitalygo\Envai\Tests\Tools\Support\ToolRequestFactory;
use Digitalygo\Envai\Tools\Execution\ToolRunOutcome;
use PHPUnit\Framework\TestCase;

final class NumericObjectKeysSecurityTest extends TestCase
{
    public function test_signed_numeric_object_keys_survive_decoding_validation_and_execution(): void
    {
        $tool = new FlexibleTool;
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );
        $canonical = '{"0":"zero","nested":{"1":42}}';
        $signature = TestKeys::signPayload("call_numeric\necho\n".$canonical, $harness->keys['secret']);
        $wire = json_encode([
            'v' => 2,
            'id' => 'evt_01ARZ3NDEKTSV4RRFFQ69G5FA0',
            'seq' => 1,
            'ts' => '2026-10-12T22:00:00Z',
            'chat_id' => 'cht_test_1',
            'turn_id' => 'cmpl_test_1',
            'agent' => null,
            'type' => 'agent.tool.request',
            'data' => [
                'call_id' => 'call_numeric',
                'tool_name' => 'echo',
                'kind' => 'client',
                'args' => json_decode($canonical, false, 512, JSON_THROW_ON_ERROR),
                'signature' => ['alg' => 'EdDSA', 'kid' => $harness->kid, 'sig' => $signature],
            ],
        ], JSON_THROW_ON_ERROR);
        $event = (new AtpEventDecoder)->decode($wire);

        $this->assertInstanceOf(AgentToolRequestEvent::class, $event);
        $this->assertSame($canonical, CanonicalJsonSerializer::serialize($event->args));
        $result = $harness->runner->handle($event, 'cht_test_1');

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertSame([0 => 'zero', 'nested' => [1 => 42]], $tool->receivedInvocations[0]->arguments);
    }

    public function test_json_object_factory_preserves_numeric_keys(): void
    {
        $object = JsonObject::fromJson('{"0":"zero"}');
        $this->assertSame('{"0":"zero"}', CanonicalJsonSerializer::serialize($object));
    }

    public function test_binary64_equivalent_integer_executes_the_verified_canonical_value(): void
    {
        $tool = new FlexibleTool;
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );
        $signed = $harness->event('call_integer', 'echo', ['n' => 9007199254740992]);
        $altered = ToolRequestFactory::create(
            $signed->callId,
            $signed->toolName,
            new JsonObject(['n' => 9007199254740993]),
            $signed->signature,
        );

        $result = $harness->runner->handle($altered, 'cht_test_1');

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertSame(9007199254740992, $tool->receivedInvocations[0]->arguments['n']);
        $this->assertSame(9007199254740993, $altered->args->get('n'));
    }
}
