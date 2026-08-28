<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Contracts;

use Digitalygo\Envai\Tests\Definitions\Tool\Fixtures\FakeEchoTool;
use Digitalygo\Envai\Tests\Definitions\Tool\Fixtures\RequiredArgumentTool;
use Digitalygo\Envai\Tools\Contracts\ReflectionClientToolFactory;
use Digitalygo\Envai\Tools\Contracts\ToolInvocation;
use Digitalygo\Envai\Tools\Contracts\ToolResult;
use PHPUnit\Framework\TestCase;

final class ClientToolContractsTest extends TestCase
{
    public function test_tool_invocation_is_an_immutable_value_object(): void
    {
        $invocation = new ToolInvocation(
            chatId: 'chat_01',
            turnId: 'turn_01',
            callId: 'call_01',
            arguments: ['text' => 'hello'],
        );

        $this->assertSame('chat_01', $invocation->chatId);
        $this->assertSame('turn_01', $invocation->turnId);
        $this->assertSame('call_01', $invocation->callId);
        $this->assertSame(['text' => 'hello'], $invocation->arguments);
    }

    public function test_tool_result_defaults_to_success(): void
    {
        $result = new ToolResult('output');

        $this->assertSame('output', $result->output);
        $this->assertFalse($result->isError);
    }

    public function test_tool_result_carries_an_error_flag(): void
    {
        $result = new ToolResult('boom', isError: true);

        $this->assertSame('boom', $result->output);
        $this->assertTrue($result->isError);
    }

    public function test_tool_result_json_serializes_the_payload_once(): void
    {
        $result = ToolResult::json(['ok' => true, 'items' => [1, 2, 3]]);

        $this->assertSame('{"ok":true,"items":[1,2,3]}', $result->output);
        $this->assertFalse($result->isError);
    }

    public function test_tool_result_json_serializes_scalars(): void
    {
        $this->assertSame('"text"', ToolResult::json('text')->output);
        $this->assertSame('42', ToolResult::json(42)->output);
        $this->assertSame('null', ToolResult::json(null)->output);
    }

    public function test_tool_result_json_rejects_non_serializable_payloads(): void
    {
        $resource = fopen('php://memory', 'r');
        $this->assertNotFalse($resource);

        try {
            $this->expectException(\JsonException::class);

            ToolResult::json($resource);
        } finally {
            fclose($resource);
        }
    }

    public function test_reflection_factory_instantiates_no_argument_handlers(): void
    {
        $tool = (new ReflectionClientToolFactory)->create(FakeEchoTool::class);

        $this->assertInstanceOf(FakeEchoTool::class, $tool);
        $this->assertSame('Echoes the given text back.', $tool->description());
        $this->assertSame('object', $tool->schema()['type']);
    }

    public function test_reflection_factory_rejects_required_constructor_arguments(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('no-argument constructor');

        (new ReflectionClientToolFactory)->create(RequiredArgumentTool::class);
    }

    public function test_reflection_factory_lets_unknown_classes_surface(): void
    {
        $this->expectException(\ReflectionException::class);

        (new ReflectionClientToolFactory)->create('App\\Ai\\Tools\\GhostTool');
    }

    public function test_handlers_return_strings_or_tool_results(): void
    {
        $tool = (new ReflectionClientToolFactory)->create(FakeEchoTool::class);

        $result = $tool->handle(new ToolInvocation('chat', 'turn', 'call', ['text' => 'hi']));

        $this->assertInstanceOf(ToolResult::class, $result);
        $this->assertSame('{"echo":"hi"}', $result->output);
    }
}
