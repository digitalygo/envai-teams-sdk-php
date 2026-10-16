<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Execution;

use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Streaming\Atp\AtpEventDecoder;
use Digitalygo\Envai\Streaming\Events\TurnCompletedEvent;
use Digitalygo\Envai\Tests\Streaming\Fixtures;
use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tests\Tools\Support\RunnerHarness;
use Digitalygo\Envai\Tools\Execution\ToolRunOutcome;
use PHPUnit\Framework\TestCase;

final class RunnerScopeSecurityTest extends TestCase
{
    public function test_mismatched_chat_is_rejected_before_handler_state_or_output(): void
    {
        $tool = new FlexibleTool;
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        try {
            $harness->runner->handle($harness->event('call_scope', 'echo'), 'cht_other');
            $this->fail('A signed request must stay bound to its envelope chat.');
        } catch (ProtocolViolationException) {
        }

        $this->assertSame(0, $tool->invocations);
        $this->assertSame(0, $harness->sender->attempts);
        $this->assertSame([], $harness->store->records());
    }

    public function test_completed_signed_call_cannot_run_again_under_another_chat(): void
    {
        $tool = new FlexibleTool;
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );
        $event = $harness->event('call_scope', 'echo');
        $harness->runner->handle($event, 'cht_test_1');

        try {
            $harness->runner->handle($event, 'cht_other');
            $this->fail('Changing chat scope must not bypass the call store.');
        } catch (ProtocolViolationException) {
        }

        $this->assertSame(1, $tool->invocations);
        $this->assertSame(1, $harness->sender->attempts);
        $this->assertCount(1, $harness->store->records());
    }

    public function test_observed_terminal_prevents_new_tool_effects_in_the_same_turn(): void
    {
        foreach (['turn.completed', 'turn.cancelled', 'turn.failed'] as $type) {
            $tool = new FlexibleTool;
            $harness = new RunnerHarness(registry: ['echo' => FlexibleTool::class], instances: [FlexibleTool::class => $tool]);
            $request = $harness->event('fresh-after-terminal', 'echo');
            $frame = Fixtures::sample($type);
            $frame['chat_id'] = $request->envelope->chatId;
            $frame['turn_id'] = $request->envelope->turnId;
            $terminal = (new AtpEventDecoder)->decode(json_encode($frame, JSON_THROW_ON_ERROR));
            $results = $harness->runner->handleEvents([$terminal, $request], $request->envelope->chatId);
            $this->assertSame(0, $tool->invocations);
            $this->assertSame(0, $harness->sender->attempts);
            $this->assertSame(ToolRunOutcome::SkippedByStore, $results[0]->outcome);
            $this->assertSame([], $harness->store->records());
            $frame['chat_id'] = 'cht_other';
            $harness->runner->observe((new AtpEventDecoder)->decode(json_encode($frame, JSON_THROW_ON_ERROR)));
        }
    }

    public function test_terminal_barrier_preserves_other_chats_and_turns(): void
    {
        foreach (['chat', 'turn'] as $scope) {
            $tool = new FlexibleTool;
            $harness = new RunnerHarness(registry: ['echo' => FlexibleTool::class], instances: [FlexibleTool::class => $tool]);
            $request = $harness->event('unrelated-terminal', 'echo');
            $frame = Fixtures::sample('turn.completed');
            $frame['chat_id'] = $scope === 'chat' ? 'cht_other' : $request->envelope->chatId;
            $frame['turn_id'] = $scope === 'turn' ? 'cmpl_other' : $request->envelope->turnId;
            $frame['data']['turn_id'] = $frame['turn_id'];
            $terminal = (new AtpEventDecoder)->decode(json_encode($frame, JSON_THROW_ON_ERROR));
            $this->assertInstanceOf(TurnCompletedEvent::class, $terminal);
            $harness->runner->handleEvents([$terminal, $request], $request->envelope->chatId);
            $this->assertSame(1, $tool->invocations);
            $this->assertSame(1, $harness->sender->attempts);
        }
    }
}
