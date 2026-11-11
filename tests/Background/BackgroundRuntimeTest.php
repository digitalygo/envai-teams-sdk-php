<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Background;

use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Models\Chat\DelegationAccepted;
use Digitalygo\Envai\Models\Chat\TaskSnapshot;
use Digitalygo\Envai\Models\Chat\TaskStatus;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Streaming\Atp\AtpEventDecoder;
use Digitalygo\Envai\Streaming\Atp\DelegatedJobAwait;
use Digitalygo\Envai\Streaming\Atp\TaskAwait;
use Digitalygo\Envai\Streaming\Events\AgentDoneEvent;
use Digitalygo\Envai\Streaming\Events\AtpEvent;
use Digitalygo\Envai\Streaming\Events\TaskEvent;
use Digitalygo\Envai\Tests\Streaming\Fixtures;
use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tests\Tools\Support\RunnerHarness;
use Digitalygo\Envai\Tools\Execution\ToolRunOutcome;
use PHPUnit\Framework\TestCase;

final class BackgroundRuntimeTest extends TestCase
{
    private function event(string $type, array $data, ?string $turn = null): AtpEvent
    {
        $wire = Fixtures::envelope($type === 'ping' ? 0 : 1, 'evt_one', '2026-10-31T12:00:00Z', $type, $data, null, $turn);
        $wire['chat_id'] = 'cht_test_1';
        $wire['data'] = (object) $data;

        return (new AtpEventDecoder)->decode(json_encode($wire, JSON_THROW_ON_ERROR));
    }

    private function tool(RunnerHarness $harness, string $id = 'call_one', bool $tamper = false): TaskEvent
    {
        $signed = $harness->event($id, 'echo', ['nested' => new JsonObject(['array' => []])]);
        $event = $this->event('task.event', [
            'task_id' => 'task_one', 'origin_turn_id' => 'origin', 'event_type' => 'agent.tool.request',
            'event_payload' => [
                'call_id' => $id, 'tool_name' => 'echo', 'kind' => 'client',
                'args' => $tamper ? (object) ['changed' => true] : $signed->args,
                'signature' => ['alg' => $signed->signature->alg, 'kid' => $signed->signature->kid, 'sig' => $signed->signature->sig],
            ],
        ]);
        self::assertInstanceOf(TaskEvent::class, $event);

        return $event;
    }

    private function harness(): array
    {
        $tool = new FlexibleTool(return: 'result');

        return [new RunnerHarness(['echo' => FlexibleTool::class], [FlexibleTool::class => $tool]), $tool];
    }

    public function test_completion_additions_and_control_decode(): void
    {
        $base = ['status' => 'succeeded', 'summary' => 'done', 'usage' => Fixtures::usage(), 'duration_ms' => 1];
        $control = $this->event('agent.done', $base, 'origin');
        $added = $this->event('agent.done', $base + ['requested_model' => 'requested', 'model' => 'actual'], 'origin');
        self::assertInstanceOf(AgentDoneEvent::class, $control);
        self::assertInstanceOf(AgentDoneEvent::class, $added);
        self::assertNull($control->model);
        self::assertSame('actual', $added->model);
        self::assertSame('requested', $added->requestedModel);
    }

    public function test_background_tool_after_origin_terminal_and_duplicate_call_id(): void
    {
        [$harness, $tool] = $this->harness();
        $terminal = $this->event('turn.completed', array_replace(Fixtures::sample('turn.completed')['data'], ['turn_id' => 'origin']), 'origin');
        $wrapped = $this->tool($harness);
        $results = $harness->runner->handleBackgroundEvents([$terminal, $wrapped, $wrapped], 'cht_test_1', liveConfirmed: true);
        self::assertSame([ToolRunOutcome::Executed, ToolRunOutcome::SkippedByStore], array_column($results, 'outcome'));
        self::assertSame(1, $tool->invocations);
        self::assertSame('cht_test_1', $harness->submission('call_one')['chatId']);
    }

    public function test_direct_and_wrapped_calls_share_deduplication_identity(): void
    {
        foreach ([true, false] as $directFirst) {
            [$harness, $tool] = $this->harness();
            $direct = $harness->event('shared', 'echo', ['nested' => new JsonObject(['array' => []])]);
            $wrapped = $this->tool($harness, 'shared');
            $events = $directFirst ? [$direct, $wrapped] : [$wrapped, $direct];
            $results = $harness->runner->handleBackgroundEvents($events, 'cht_test_1', liveConfirmed: true);
            self::assertSame([ToolRunOutcome::Executed, ToolRunOutcome::SkippedByStore], array_column($results, 'outcome'));
            self::assertSame(1, $tool->invocations);
        }
    }

    public function test_signature_failure_never_invokes_or_submits(): void
    {
        [$harness, $tool] = $this->harness();
        try {
            $harness->runner->handleBackgroundEvent($this->tool($harness, tamper: true), 'cht_test_1');
            self::fail('Tampered signature was admitted.');
        } catch (ProtocolViolationException) {
            self::assertSame(0, $tool->invocations);
            self::assertNull($harness->sender->byCallId('call_one'));
        }
    }

    public function test_replay_result_and_task_terminal_barriers_precede_dispatch(): void
    {
        foreach (['result', 'completed', 'failed', 'cancelled'] as $barrier) {
            [$harness, $tool] = $this->harness();
            $event = $barrier === 'result'
                ? $this->event('task.event', ['task_id' => 'task_one', 'origin_turn_id' => 'origin', 'event_type' => 'agent.tool.result', 'event_payload' => ['call_id' => 'call_one', 'output' => 'prior', 'is_error' => false]])
                : $this->event('task.'.$barrier, ['task_id' => 'task_one', 'origin_turn_id' => 'origin', 'status' => $barrier]);
            $harness->runner->handleBackgroundEvents([$this->tool($harness), $event, $this->event('ping', [])], 'cht_test_1');
            self::assertSame(0, $tool->invocations, $barrier);
        }
    }

    public function test_pending_replay_dispatches_only_after_live_ping(): void
    {
        [$harness, $tool] = $this->harness();
        $harness->runner->handleBackgroundEvents([$this->tool($harness)], 'cht_test_1');
        self::assertSame(0, $tool->invocations);
        $harness->runner->handleBackgroundEvents([$this->tool($harness), $this->event('ping', [])], 'cht_test_1');
        self::assertSame(1, $tool->invocations);
    }

    public function test_foreground_runner_does_not_execute_task_wrappers(): void
    {
        [$harness, $tool] = $this->harness();
        self::assertSame([], $harness->runner->handleEvents([$this->tool($harness)], 'cht_test_1'));
        self::assertSame(0, $tool->invocations);
    }

    public function test_final_job_waiter_uses_origin_task_output_after_ack_and_dispatches_tools(): void
    {
        [$harness, $tool] = $this->harness();
        $reads = 0;
        $lookup = static function () use (&$reads): TaskSnapshot {
            return ++$reads < 3
                ? new TaskSnapshot('job', 'origin', TaskStatus::Running, targetChatId: 'cht_test_1')
                : new TaskSnapshot('job', 'origin', TaskStatus::Completed, targetChatId: 'cht_test_1', output: 'FINAL SYNTHESIS', resultTurnId: 'final_turn');
        };
        $ack = $this->event('turn.completed', array_replace(Fixtures::sample('turn.completed')['data'], ['turn_id' => 'ack_turn']), 'ack_turn');
        $result = DelegatedJobAwait::terminal(new DelegationAccepted('job', 'cht_test_1', 'ack_turn'), [$ack, $this->tool($harness)], $lookup, $harness->runner);
        self::assertSame('FINAL SYNTHESIS', $result->output);
        self::assertSame('final_turn', $result->resultTurnId);
        self::assertSame(1, $tool->invocations);
    }

    public function test_failed_and_cancelled_jobs_remain_explicit_terminal_states(): void
    {
        foreach ([TaskStatus::Failed, TaskStatus::Cancelled] as $status) {
            $task = new TaskSnapshot('job', 'origin', $status, error: 'reason');
            self::assertSame($task, DelegatedJobAwait::terminal(new DelegationAccepted('job', 'target', 'ack'), [], static fn () => $task));
            $event = $this->event('task.'.$status->value, $task->toArray());
            self::assertSame($status, TaskAwait::terminal([$event], 'cht_test_1', 'job')->status);
        }
    }

    public function test_eof_is_unknown_outcome_not_acknowledgment_success(): void
    {
        $this->expectException(TransportException::class);
        DelegatedJobAwait::terminal(new DelegationAccepted('job', 'target', 'ack'), [], static fn () => new TaskSnapshot('job', 'origin', TaskStatus::Running));
    }

    public function test_foreign_chat_wrapper_rejected(): void
    {
        [$harness] = $this->harness();
        $this->expectException(ProtocolViolationException::class);
        $harness->runner->handleBackgroundEvent($this->tool($harness), 'another-chat');
    }
}
