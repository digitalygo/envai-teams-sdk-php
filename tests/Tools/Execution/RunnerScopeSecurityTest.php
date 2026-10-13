<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Execution;

use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tests\Tools\Support\RunnerHarness;
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
}
