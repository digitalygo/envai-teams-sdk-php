<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Tools\Execution;

use Digitalygo\Envai\Exceptions\ConflictException;
use Digitalygo\Envai\Exceptions\NotFoundException;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Exceptions\ServerException;
use Digitalygo\Envai\Exceptions\TransportException;
use Digitalygo\Envai\Models\Common\ApiError;
use Digitalygo\Envai\Models\Json\CanonicalJsonSerializer;
use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Streaming\Atp\AtpEventDecoder;
use Digitalygo\Envai\Streaming\Events\AgentToolRequestEvent;
use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tests\Tools\Support\RunnerHarness;
use Digitalygo\Envai\Tests\Tools\Support\TestKeys;
use Digitalygo\Envai\Tests\Tools\Support\ThrowingClientToolFactory;
use Digitalygo\Envai\Tests\Tools\Support\ToolOutputPayload;
use Digitalygo\Envai\Tests\Tools\Support\ToolRequestFactory;
use Digitalygo\Envai\Tools\Contracts\SafeToolMessageException;
use Digitalygo\Envai\Tools\Contracts\ToolResult;
use Digitalygo\Envai\Tools\Execution\ClientToolRunner;
use Digitalygo\Envai\Tools\Execution\RunnerOptions;
use Digitalygo\Envai\Tools\Execution\ToolRunOutcome;
use Digitalygo\Envai\Tools\Execution\ToolRunResult;
use Digitalygo\Envai\Tools\State\ClientToolCallStatus;
use Digitalygo\Envai\Tools\State\ToolCallIdentity;
use PHPUnit\Framework\TestCase;

final class ClientToolRunnerTest extends TestCase
{
    private const CHAT_ID = 'cht_test_1';

    public function test_valid_signature_executes_the_handler_once_and_submits_the_exact_output(): void
    {
        $tool = new FlexibleTool(return: ToolResult::json(['echo' => 'hi']));
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $result = $harness->runner->handle($harness->event('call_01', 'echo', ['text' => 'hello']), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertTrue($result->outputSubmitted);
        $this->assertSame(ClientToolCallStatus::Resolved, $result->storeStatus);
        $this->assertSame(1, $tool->invocations);
        $this->assertSame('call_01', $tool->receivedInvocations[0]->callId);
        $this->assertSame(['text' => 'hello'], $tool->receivedInvocations[0]->arguments);

        $submission = $harness->submission('call_01');
        $this->assertSame('{"echo":"hi"}', $submission['output']);
        $this->assertFalse($submission['isError']);
        $this->assertSame(self::CHAT_ID, $submission['chatId']);
    }

    public function test_string_result_is_submitted_as_is(): void
    {
        $tool = new FlexibleTool(return: 'plain string output');
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $result = $harness->runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $submission = $harness->submission('call_01');
        $this->assertSame('plain string output', $submission['output']);
        $this->assertFalse($submission['isError']);
    }

    public function test_tool_result_error_flag_is_passed_through(): void
    {
        $tool = new FlexibleTool(return: new ToolResult('nope', isError: true));
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $harness->runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $submission = $harness->submission('call_01');
        $this->assertTrue($submission['isError']);
        $this->assertSame('nope', $submission['output']);
    }

    public function test_invalid_signature_throws_and_nothing_is_executed_or_submitted(): void
    {
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => new FlexibleTool],
        );

        $tamperKeys = TestKeys::generate();
        $event = ToolRequestFactory::signed(
            'call_01',
            'echo',
            ['text' => 'hello'],
            $tamperKeys['secret'],
            $harness->kid,
        );

        try {
            $harness->runner->handle($event, self::CHAT_ID);
            $this->fail('Expected a ProtocolViolationException for the wrong key.');
        } catch (ProtocolViolationException) {
        }

        $this->assertSame(0, $harness->sender->attempts);
        $this->assertSame([], $harness->store->records());
    }

    public function test_tampered_args_throw_and_the_handler_never_runs(): void
    {
        $tool = new FlexibleTool(return: ToolResult::json(['ok' => true]));
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $event = $harness->event('call_01', 'echo', ['text' => 'signed']);
        $tampered = ToolRequestFactory::create(
            'call_01',
            'echo',
            new JsonObject(['text' => 'tampered']),
            $event->signature,
        );

        try {
            $harness->runner->handle($tampered, self::CHAT_ID);
            $this->fail('Expected a ProtocolViolationException for the tampered preimage.');
        } catch (ProtocolViolationException) {
        }

        $this->assertSame(0, $tool->invocations);
        $this->assertSame(0, $harness->sender->attempts);
        $this->assertSame([], $harness->store->records());
    }

    public function test_unknown_tool_submits_a_controlled_error_and_consumes_the_call(): void
    {
        $tool = new FlexibleTool(return: 'never used');
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $result = $harness->runner->handle($harness->event('call_01', 'ghost_tool', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::UnknownTool, $result->outcome);
        $this->assertTrue($result->outputSubmitted);
        $this->assertSame(ClientToolCallStatus::Resolved, $result->storeStatus);
        $this->assertSame(0, $tool->invocations);

        $submission = $harness->submission('call_01');
        $this->assertTrue($submission['isError']);
        $payload = ToolOutputPayload::decode($submission['output']);
        $this->assertSame('tool_not_found', $payload->string('code'));
        $this->assertStringContainsString('ghost_tool', $payload->string('message'));
    }

    public function test_unknown_tool_replay_is_skipped_without_a_second_submission(): void
    {
        $harness = new RunnerHarness(registry: ['echo' => FlexibleTool::class]);

        $event = $harness->event('call_01', 'ghost_tool', []);
        $harness->runner->handle($event, self::CHAT_ID);
        $second = $harness->runner->handle($event, self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::SkippedByStore, $second->outcome);
        $this->assertSame(1, $harness->sender->attempts);
    }

    public function test_missing_required_argument_submits_invalid_arguments(): void
    {
        $tool = new FlexibleTool(schema: [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
        ]);
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $result = $harness->runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::InvalidArguments, $result->outcome);
        $this->assertTrue($result->outputSubmitted);
        $this->assertSame(ClientToolCallStatus::Resolved, $result->storeStatus);
        $this->assertSame(0, $tool->invocations);

        $payload = ToolOutputPayload::decode($harness->submission('call_01')['output']);
        $this->assertSame('invalid_arguments', $payload->string('code'));
        $this->assertSame(['Missing required property "text".'], $payload->stringList('violations'));
    }

    public function test_wrong_type_argument_submits_invalid_arguments(): void
    {
        $tool = new FlexibleTool(schema: [
            'type' => 'object',
            'properties' => ['count' => ['type' => 'integer']],
            'required' => ['count'],
        ]);
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $result = $harness->runner->handle($harness->event('call_01', 'echo', ['count' => 'three']), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::InvalidArguments, $result->outcome);
        $this->assertSame(0, $tool->invocations);

        $payload = ToolOutputPayload::decode($harness->submission('call_01')['output']);
        $this->assertSame('invalid_arguments', $payload->string('code'));
        $this->assertStringContainsString('"count" must be an integer', $payload->stringList('violations')[0]);
    }

    public function test_integer_float_with_zero_fraction_is_accepted_as_integer(): void
    {
        $tool = new FlexibleTool(schema: [
            'type' => 'object',
            'properties' => ['count' => ['type' => 'integer']],
            'required' => ['count'],
        ]);
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $result = $harness->runner->handle($harness->event('call_01', 'echo', ['count' => 3.0]), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertSame(1, $tool->invocations);
    }

    public function test_extra_properties_are_allowed_by_the_schema_validator(): void
    {
        $tool = new FlexibleTool(schema: [
            'type' => 'object',
            'properties' => ['text' => ['type' => 'string']],
            'required' => ['text'],
        ]);
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $result = $harness->runner->handle(
            $harness->event('call_01', 'echo', ['text' => 'hi', 'extra' => 'allowed']),
            self::CHAT_ID,
        );

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertSame(1, $tool->invocations);
    }

    public function test_handler_exceeding_the_deadline_is_not_submitted_and_marked_ambiguous(): void
    {
        $tool = new FlexibleTool(return: 'late output', sleepMicroseconds: 1_200_000);
        $harness = new RunnerHarness(
            registry: ['slow' => ['class' => FlexibleTool::class, 'timeout_seconds' => 1]],
            instances: [FlexibleTool::class => $tool],
        );

        $result = $harness->runner->handle($harness->event('call_01', 'slow', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::TimeoutExceeded, $result->outcome);
        $this->assertFalse($result->outputSubmitted);
        $this->assertSame(ClientToolCallStatus::Ambiguous, $result->storeStatus);
        $this->assertSame(1, $tool->invocations);
        $this->assertSame(0, $harness->sender->attempts);
    }

    public function test_fast_handler_is_submitted_before_the_deadline(): void
    {
        $tool = new FlexibleTool(return: 'quick output');
        $harness = new RunnerHarness(
            registry: ['slow' => ['class' => FlexibleTool::class, 'timeout_seconds' => 120]],
            instances: [FlexibleTool::class => $tool],
        );

        $result = $harness->runner->handle($harness->event('call_01', 'slow', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertTrue($result->outputSubmitted);
    }

    public function test_allow_submission_past_deadline_submits_the_output_anyway(): void
    {
        $tool = new FlexibleTool(return: 'late but allowed', sleepMicroseconds: 1_200_000);
        $harness = new RunnerHarness(
            registry: ['slow' => ['class' => FlexibleTool::class, 'timeout_seconds' => 1]],
            instances: [FlexibleTool::class => $tool],
            options: new RunnerOptions(allowSubmissionPastDeadline: true),
        );

        $result = $harness->runner->handle($harness->event('call_01', 'slow', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertTrue($result->outputSubmitted);
        $this->assertSame('late but allowed', $harness->submission('call_01')['output']);
    }

    public function test_output_over_128_kib_is_replaced_by_a_controlled_error(): void
    {
        $tool = new FlexibleTool(return: str_repeat('x', 131_073));
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $result = $harness->runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::OutputTooLarge, $result->outcome);
        $this->assertTrue($result->outputSubmitted);
        $this->assertSame(ClientToolCallStatus::Resolved, $result->storeStatus);

        $submission = $harness->submission('call_01');
        $this->assertTrue($submission['isError']);
        $this->assertSame('output_too_large', ToolOutputPayload::decode($submission['output'])->string('code'));
    }

    public function test_output_at_exactly_128_kib_is_accepted_as_is(): void
    {
        $output = str_repeat('a', 131_072);
        $tool = new FlexibleTool(return: $output);
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $result = $harness->runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertSame($output, $harness->submission('call_01')['output']);
    }

    public function test_handler_exception_delivers_a_controlled_generic_error(): void
    {
        $tool = new FlexibleTool(throw: new \RuntimeException('boom'));
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $result = $harness->runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::HandlerError, $result->outcome);
        $this->assertTrue($result->outputSubmitted);
        $this->assertSame(ClientToolCallStatus::Resolved, $result->storeStatus);

        $submission = $harness->submission('call_01');
        $this->assertTrue($submission['isError']);
        $payload = ToolOutputPayload::decode($submission['output']);
        $this->assertSame('handler_error', $payload->string('code'));
        $this->assertSame('The client tool handler failed.', $payload->string('message'));
        $this->assertStringNotContainsString('boom', $payload->string('message'));
    }

    public function test_safe_tool_message_exception_exposes_its_message(): void
    {
        $tool = new FlexibleTool(throw: new SafeToolMessageException('The upstream quota is exhausted; retry later.'));
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $result = $harness->runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::HandlerError, $result->outcome);

        $payload = ToolOutputPayload::decode($harness->submission('call_01')['output']);
        $this->assertSame('handler_error', $payload->string('code'));
        $this->assertSame('The upstream quota is exhausted; retry later.', $payload->string('message'));
    }

    public function test_safe_handler_exception_message_is_truncated_to_a_safe_length(): void
    {
        $tool = new FlexibleTool(throw: new SafeToolMessageException(str_repeat('y', 5000)));
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $harness->runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $payload = ToolOutputPayload::decode($harness->submission('call_01')['output']);
        $this->assertLessThanOrEqual(2000, strlen($payload->string('message')));
    }

    public function test_safe_handler_exception_with_invalid_utf8_message_is_sanitized(): void
    {
        $tool = new FlexibleTool(throw: new SafeToolMessageException("\xC3\x28"));
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $harness->runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $payload = ToolOutputPayload::decode($harness->submission('call_01')['output']);
        $this->assertSame('The handler error message is not valid UTF-8.', $payload->string('message'));
    }

    public function test_empty_safe_handler_exception_message_falls_back_to_the_generic_message(): void
    {
        $tool = new FlexibleTool(throw: new SafeToolMessageException(''));
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $harness->runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $payload = ToolOutputPayload::decode($harness->submission('call_01')['output']);
        $this->assertSame('The client tool handler failed.', $payload->string('message'));
    }

    public function test_factory_failure_is_delivered_as_a_handler_error(): void
    {
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
        );
        $throwing = new ThrowingClientToolFactory(new \RuntimeException('cannot instantiate'));

        $runner = new ClientToolRunner(
            $harness->registry,
            $throwing,
            $harness->verifier,
            $harness->store,
            $harness->sender,
        );

        $result = $runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::HandlerError, $result->outcome);
        $payload = ToolOutputPayload::decode($harness->submission('call_01')['output']);
        $this->assertSame('handler_error', $payload->string('code'));
        $this->assertSame('The client tool handler failed.', $payload->string('message'));
    }

    public function test_replay_after_resolved_skips_the_handler_and_submits_nothing(): void
    {
        $tool = new FlexibleTool(return: 'once');
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $event = $harness->event('call_01', 'echo', ['text' => 'hi']);
        $first = $harness->runner->handle($event, self::CHAT_ID);
        $second = $harness->runner->handle($event, self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::Executed, $first->outcome);
        $this->assertSame(ToolRunOutcome::SkippedByStore, $second->outcome);
        $this->assertSame(ClientToolCallStatus::Resolved, $second->storeStatus);
        $this->assertFalse($second->outputSubmitted);
        $this->assertSame(1, $tool->invocations);
        $this->assertSame(1, $harness->sender->attempts);
    }

    public function test_already_running_call_is_skipped_without_submission(): void
    {
        $tool = new FlexibleTool(return: 'never');
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $harness->store->begin(new ToolCallIdentity(self::CHAT_ID, 'call_01', 'echo'));

        $result = $harness->runner->handle($harness->event('call_01', 'echo', ['text' => 'hi']), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::SkippedByStore, $result->outcome);
        $this->assertSame(ClientToolCallStatus::Started, $result->storeStatus);
        $this->assertFalse($result->outputSubmitted);
        $this->assertSame(0, $tool->invocations);
        $this->assertSame(0, $harness->sender->attempts);
    }

    public function test_sender_409_tool_already_resolved_marks_the_call_resolved(): void
    {
        $tool = new FlexibleTool(return: 'output');
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );
        $harness->sender->throw = new ConflictException('already resolved', 409, new ApiError('conflict', 'tool_already_resolved', 'already resolved'));

        $result = $harness->runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertTrue($result->outputSubmitted);
        $this->assertSame(ClientToolCallStatus::Resolved, $result->storeStatus);
        $this->assertSame(1, $harness->sender->attempts);
    }

    public function test_sender_409_tool_timed_out_marks_the_call_ambiguous(): void
    {
        $tool = new FlexibleTool(return: 'late output');
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );
        $harness->sender->throw = new ConflictException('timed out', 409, new ApiError('conflict', 'tool_timed_out', 'timed out'));

        $result = $harness->runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertFalse($result->outputSubmitted);
        $this->assertSame(ClientToolCallStatus::Ambiguous, $result->storeStatus);
    }

    public function test_sender_404_after_terminal_marks_the_call_ambiguous(): void
    {
        $tool = new FlexibleTool(return: 'output');
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );
        $harness->sender->throw = new NotFoundException('call not found', 404, new ApiError('not_found', null, 'call not found'));

        $result = $harness->runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertFalse($result->outputSubmitted);
        $this->assertSame(ClientToolCallStatus::Ambiguous, $result->storeStatus);
    }

    public function test_sender_transport_exception_marks_ambiguous_without_retry(): void
    {
        $tool = new FlexibleTool(return: 'output');
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );
        $harness->sender->throw = new TransportException('network down');

        $result = $harness->runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::DeliveryFailed, $result->outcome);
        $this->assertFalse($result->outputSubmitted);
        $this->assertSame(ClientToolCallStatus::Ambiguous, $result->storeStatus);
        $this->assertSame('network down', $result->error);
        $this->assertSame(1, $harness->sender->attempts);
    }

    public function test_sender_other_api_exception_is_recorded_as_delivery_failed(): void
    {
        $tool = new FlexibleTool(return: 'output');
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );
        $harness->sender->throw = new ServerException('server exploded', 500, new ApiError('server_error', null, 'server exploded'));

        $result = $harness->runner->handle($harness->event('call_01', 'echo', []), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::DeliveryFailed, $result->outcome);
        $this->assertSame(ClientToolCallStatus::Ambiguous, $result->storeStatus);
        $this->assertSame('server exploded', $result->error);
        $this->assertSame(1, $harness->sender->attempts);
    }

    public function test_concurrency_guard_refuses_requests_beyond_max_concurrent(): void
    {
        /** @var \ArrayObject<int, ToolRunResult> $innerResult */
        $innerResult = new \ArrayObject;
        $alpha = new FlexibleTool(return: ToolResult::json(['echo' => 'alpha']));

        $harness = new RunnerHarness(
            registry: ['alpha' => FlexibleTool::class, 'beta' => FlexibleTool::class],
            instances: [FlexibleTool::class => $alpha],
            options: new RunnerOptions(maxConcurrent: 1),
        );

        $alpha->onHandle = static function () use ($harness, $innerResult): void {
            $innerResult[] = $harness->runner->handle($harness->event('call_beta', 'beta', ['text' => 'x']), self::CHAT_ID);
        };

        $result = $harness->runner->handle($harness->event('call_alpha', 'alpha', ['text' => 'go']), self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);

        $busy = $innerResult[0];
        $this->assertInstanceOf(ToolRunResult::class, $busy);
        $this->assertSame(ToolRunOutcome::ToolBusy, $busy->outcome);
        $this->assertTrue($busy->outputSubmitted);
        $this->assertSame(ClientToolCallStatus::Resolved, $busy->storeStatus);

        $this->assertSame(1, $alpha->invocations);

        $busySubmission = $harness->submission('call_beta');
        $this->assertTrue($busySubmission['isError']);
        $this->assertSame('tool_busy', ToolOutputPayload::decode($busySubmission['output'])->string('code'));
    }

    public function test_handle_events_filters_non_tool_events(): void
    {
        $tool = new FlexibleTool(return: 'ok');
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $results = $harness->runner->handleEvents([
            new \stdClass,
            $harness->event('call_01', 'echo', []),
            new \stdClass,
            $harness->event('call_02', 'echo', []),
        ], self::CHAT_ID);

        $this->assertCount(2, $results);
        $this->assertSame('call_01', $results[0]->callId);
        $this->assertSame('call_02', $results[1]->callId);
        $this->assertSame(2, $tool->invocations);
    }

    public function test_turn_id_is_forwarded_to_the_invocation(): void
    {
        $tool = new FlexibleTool(return: 'ok');
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $harness->runner->handle($harness->event('call_01', 'echo', [], turnId: 'cmpl_special'), self::CHAT_ID);

        $this->assertSame('cmpl_special', $tool->receivedInvocations[0]->turnId);
    }

    public function test_missing_turn_id_defaults_to_an_empty_string_on_the_invocation(): void
    {
        $tool = new FlexibleTool(return: 'ok');
        $harness = new RunnerHarness(
            registry: ['echo' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $harness->runner->handle($harness->event('call_01', 'echo', [], turnId: null), self::CHAT_ID);

        $this->assertSame('', $tool->receivedInvocations[0]->turnId);
    }

    public function test_store_status_is_ambiguous_after_a_timeout(): void
    {
        $tool = new FlexibleTool(return: 'x', sleepMicroseconds: 1_200_000);
        $harness = new RunnerHarness(
            registry: ['slow' => ['class' => FlexibleTool::class, 'timeout_seconds' => 1]],
            instances: [FlexibleTool::class => $tool],
        );

        $harness->runner->handle($harness->event('call_01', 'slow', []), self::CHAT_ID);

        $record = $harness->record('call_01');
        $this->assertNotNull($record);
        $this->assertSame(ClientToolCallStatus::Ambiguous, $record->status);
    }

    public function test_signed_decoded_typed_arguments_are_unwrapped_after_verification(): void
    {
        $tool = new FlexibleTool(schema: [
            'type' => 'object',
            'properties' => [
                'settings' => [
                    'type' => 'object',
                    'properties' => [
                        'region' => ['type' => 'string'],
                        'overrides' => ['type' => 'object'],
                    ],
                ],
                'items' => [
                    'type' => 'array',
                    'items' => ['type' => 'object'],
                ],
            ],
            'required' => ['settings', 'items'],
        ]);
        $harness = new RunnerHarness(
            registry: ['report' => FlexibleTool::class],
            instances: [FlexibleTool::class => $tool],
        );

        $callId = 'call_typed_01';
        $toolName = 'report';
        $wireArgs = [
            'settings' => [
                'region' => 'eu',
                'overrides' => new \stdClass,
            ],
            'items' => [
                ['id' => 'one', 'labels' => ['primary']],
                new \stdClass,
            ],
        ];
        $canonical = '{"items":[{"id":"one","labels":["primary"]},{}],"settings":{"overrides":{},"region":"eu"}}';
        $signature = TestKeys::signPayload(
            $callId."\n".$toolName."\n".$canonical,
            $harness->keys['secret'],
        );
        $event = (new AtpEventDecoder)->decode($this->requestWire($callId, $toolName, $harness->kid, $signature, $wireArgs));

        $this->assertInstanceOf(AgentToolRequestEvent::class, $event);
        $this->assertSame($canonical, CanonicalJsonSerializer::serialize($event->args));

        $verified = $harness->verifier->verify($event);
        $settings = $verified->args->get('settings');
        $this->assertInstanceOf(JsonObject::class, $settings);
        $overrides = $settings->get('overrides');
        $this->assertInstanceOf(JsonObject::class, $overrides);
        $this->assertSame('{}', CanonicalJsonSerializer::serialize($overrides));
        $this->assertInstanceOf(JsonArray::class, $verified->args->get('items'));

        $result = $harness->runner->handle($event, self::CHAT_ID);

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertSame(1, $tool->invocations);
        $this->assertCount(1, $tool->receivedInvocations);
        $this->assertSame([
            'settings' => [
                'region' => 'eu',
                'overrides' => [],
            ],
            'items' => [
                ['id' => 'one', 'labels' => ['primary']],
                [],
            ],
        ], $tool->receivedInvocations[0]->arguments);
        $this->assertIsArray($tool->receivedInvocations[0]->arguments['settings']);
        $this->assertIsArray($tool->receivedInvocations[0]->arguments['settings']['overrides']);
        $this->assertIsArray($tool->receivedInvocations[0]->arguments['items']);
        $this->assertIsArray($tool->receivedInvocations[0]->arguments['items'][0]);
        $this->assertIsArray($tool->receivedInvocations[0]->arguments['items'][0]['labels']);
    }

    /**
     * Builds a signed `agent.tool.request` wire envelope for runner tests.
     *
     * @param  array<string, mixed>  $args
     */
    private function requestWire(string $callId, string $toolName, string $kid, string $signature, array $args): string
    {
        return json_encode([
            'v' => 2,
            'id' => 'evt_typed_01',
            'seq' => 1,
            'ts' => '2026-08-29T14:00:00Z',
            'chat_id' => self::CHAT_ID,
            'turn_id' => 'cmpl_test_1',
            'agent' => null,
            'type' => 'agent.tool.request',
            'data' => [
                'kind' => 'client',
                'call_id' => $callId,
                'tool_name' => $toolName,
                'args' => $args,
                'signature' => [
                    'alg' => 'EdDSA',
                    'kid' => $kid,
                    'sig' => $signature,
                ],
            ],
        ], JSON_THROW_ON_ERROR);
    }
}
