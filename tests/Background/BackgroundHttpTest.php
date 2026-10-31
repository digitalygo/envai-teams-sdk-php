<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Background;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\NotFoundException;
use Digitalygo\Envai\Exceptions\ServerException;
use Digitalygo\Envai\Exceptions\UnsupportedCapabilityException;
use Digitalygo\Envai\Models\Chat\ChatMessageRequest;
use Digitalygo\Envai\Models\Chat\DelegationRequest;
use Digitalygo\Envai\Models\Chat\DeliveryOutcome;
use Digitalygo\Envai\Models\Chat\DeliveryRequest;
use Digitalygo\Envai\Models\Chat\PhaseClassificationRequest;
use Digitalygo\Envai\Models\Chat\TaskStatus;
use Digitalygo\Envai\Tests\Streaming\Atp\FakeEventStreamConnection;
use Digitalygo\Envai\Tests\Streaming\Fixtures;
use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tests\Tools\Support\RunnerHarness;
use Digitalygo\Envai\Tests\Wiki\ContractNegotiationTest;
use Digitalygo\Envai\Tests\Wiki\ContractTestTransport;
use PHPUnit\Framework\TestCase;

final class BackgroundHttpTest extends TestCase
{
    private function setupClient(array $features): array
    {
        $transport = new ContractTestTransport;
        $transport->responses[] = ContractNegotiationTest::response(ContractNegotiationTest::capabilities() + []);
        $transport->responses[0] = ContractNegotiationTest::response(array_replace(ContractNegotiationTest::capabilities(), ['families' => ['task'], 'features' => $features]));
        $client = new EnvaiClient(new EnvaiConfig('https://envai.example', new SensitiveString('test'), nativeTasks: true), $transport);

        return [$client, $transport];
    }

    public function test_native_routes_and_fields_and_one_preflight(): void
    {
        [$client, $transport] = $this->setupClient(['native_deliveries', 'cross_team_delegation', 'detached_background', 'phase_classification', 'strict_model', 'delegation_notify_origin']);
        $transport->responses[] = ContractNegotiationTest::response(['event_id' => 'e', 'status' => 'queued', 'duplicate' => false], status: 202);
        $transport->responses[] = ContractNegotiationTest::response(['task_id' => 't', 'target_chat_id' => 'target', 'turn_id' => 'ack'], status: 202);
        $transport->responses[] = ContractNegotiationTest::response(['task_id' => 't', 'origin_turn_id' => 'o', 'status' => 'running']);
        $transport->responses[] = ContractNegotiationTest::response(['task_id' => 't', 'status' => 'cancelled', 'subtree' => true], status: 202);
        $transport->responses[] = ContractNegotiationTest::response(['decision' => 'NOT BLOCKED', 'model' => 'configured', 'request_id' => 'r', 'invocation_id' => 'i']);
        $client->chats()->deliver('c/a', new DeliveryRequest('w', 'e', 'o', 't', DeliveryOutcome::Completed, 'output'));
        $client->chats()->delegate('c/a', new DelegationRequest('w', 'e2', 'o', 'target', 'prompt', notifyOrigin: false, strictModel: true));
        self::assertSame(TaskStatus::Running, $client->chats()->getTask('c/a', 't/x')->status);
        self::assertTrue($client->chats()->cancelTask('c/a', 't/x', subtree: true)->subtree);
        $client->chats()->classifyPhase('c/a', new PhaseClassificationRequest('r', ['context'], 'phase', 1, 'final'));
        self::assertCount(6, $transport->requests);
        self::assertStringEndsWith('/chats/c%2Fa/tasks/t%2Fx/cancel', $transport->requests[4]->uri);
        self::assertFalse(json_decode($transport->requests[2]->body, true)['notify_origin']);
        self::assertTrue(json_decode($transport->requests[2]->body, true)['strict_model']);
        foreach ($transport->requests as $request) {
            self::assertSame(['3'], $request->headers['x-envai-contract-version']);
            self::assertSame(['native-tasks-v1'], $request->headers['x-envai-extensions']);
        }
    }

    public function test_delegated_helper_keeps_target_stream_through_final_job(): void
    {
        [$client, $transport] = $this->setupClient(['cross_team_delegation', 'detached_background', 'delegated_job_lifecycle']);
        $transport->responses[] = ContractNegotiationTest::response(['task_id' => 'job', 'target_chat_id' => 'target', 'turn_id' => 'ack'], status: 202);
        $running = ['task_id' => 'job', 'origin_turn_id' => 'origin', 'target_chat_id' => 'target', 'status' => 'running'];
        $transport->responses[] = ContractNegotiationTest::response($running);
        $transport->responses[] = ContractNegotiationTest::response($running);
        $transport->responses[] = ContractNegotiationTest::response(array_replace($running, ['status' => 'completed', 'output' => 'FINAL', 'result_turn_id' => 'synthesis']));
        $ping = Fixtures::ping();
        $ping['chat_id'] = 'target';
        $ack = Fixtures::envelope(1, 'evt_ack', '2026-10-31T00:00:00Z', 'turn.completed', array_replace(Fixtures::sample('turn.completed')['data'], ['turn_id' => 'ack']), null, 'ack');
        $ack['chat_id'] = 'target';
        $connection = FakeEventStreamConnection::fromEnvelopes([$ping, $ack, $ping]);
        $transport->connections[] = $connection;
        $result = $client->chat('w', 'origin-chat')->delegateAndWait(new DelegationRequest('w', 'event', 'origin', 'target', 'prompt'));
        self::assertSame('FINAL', $result->output);
        self::assertSame('synthesis', $result->resultTurnId);
        self::assertTrue($connection->isClosed());
        self::assertSame(['native-tasks-v1'], $transport->streamRequests[0]->headers['x-envai-extensions']);
        self::assertStringEndsWith('/chats/origin-chat/tasks/job', $transport->requests[4]->uri);
    }

    public function test_delegation_retry_replays_pending_tools_after_terminal_barriers(): void
    {
        foreach ([false, true] as $alreadyResolved) {
            [$client, $transport] = $this->setupClient(['cross_team_delegation', 'detached_background', 'delegated_job_lifecycle']);
            $transport->responses[] = ContractNegotiationTest::response(['task_id' => 'job', 'target_chat_id' => 'cht_test_1', 'turn_id' => 'ack'], status: 202);
            $transport->responses[] = ContractNegotiationTest::response(['task_id' => 'job', 'origin_turn_id' => 'origin', 'status' => 'completed', 'output' => 'FINAL', 'result_turn_id' => 'synthesis']);
            $tool = new FlexibleTool(return: 'done');
            $harness = new RunnerHarness(['echo' => $tool::class], [$tool::class => $tool]);
            $signed = $harness->event('retained-call', 'echo', ['x' => 1]);
            $data = ['task_id' => 'child', 'origin_turn_id' => 'ack', 'event_type' => 'agent.tool.request', 'event_payload' => ['call_id' => $signed->callId, 'tool_name' => 'echo', 'kind' => 'client', 'args' => $signed->args, 'signature' => ['alg' => 'EdDSA', 'kid' => $signed->signature->kid, 'sig' => $signed->signature->sig]]];
            $request = Fixtures::envelope(1, 'request', '2026-10-31T00:00:00Z', 'task.event', $data, null, null);
            $request['chat_id'] = 'cht_test_1';
            $frames = [$request];
            if ($alreadyResolved) {
                $result = Fixtures::envelope(2, 'result', '2026-10-31T00:00:00Z', 'task.event', ['task_id' => 'child', 'origin_turn_id' => 'ack', 'event_type' => 'agent.tool.result', 'event_payload' => ['call_id' => 'retained-call', 'output' => 'previous', 'is_error' => false]], null, null);
                $result['chat_id'] = 'cht_test_1';
                $frames[] = $result;
            }
            $ping = Fixtures::ping();
            $ping['chat_id'] = 'cht_test_1';
            $frames[] = $ping;
            $transport->connections[] = FakeEventStreamConnection::fromEnvelopes($frames);
            $client->chat('w', 'origin-chat')->delegateAndWait(new DelegationRequest('w', 'stable-event', 'origin', 'cht_test_1', 'prompt'), $harness->runner);
            self::assertSame($alreadyResolved ? 0 : 1, $tool->invocations);
        }
    }

    public function test_native_message_rejects_server_that_ignores_extension(): void
    {
        [$client, $transport] = $this->setupClient([]);
        try {
            $client->chats()->sendMessage('c', new ChatMessageRequest('native'));
            self::fail('Unadvertised native execution accepted.');
        } catch (UnsupportedCapabilityException) {
            self::assertCount(1, $transport->requests);
        }
    }

    public function test_default_client_does_not_enable_native_extension(): void
    {
        $transport = new ContractTestTransport;
        $transport->responses = [ContractNegotiationTest::response(ContractNegotiationTest::capabilities()), ContractNegotiationTest::response(['status' => 'accepted', 'turn_id' => 't'], status: 202)];
        $client = new EnvaiClient(new EnvaiConfig('https://envai.example', new SensitiveString('test')), $transport);
        $client->chats()->sendMessage('c', new ChatMessageRequest('ordinary'));
        foreach ($transport->requests as $request) {
            self::assertArrayNotHasKey('x-envai-extensions', $request->headers);
        }
    }

    public function test_missing_capability_prevents_post(): void
    {
        [$client, $transport] = $this->setupClient([]);
        try {
            $client->chats()->delegate('c', new DelegationRequest('w', 'e', 'o', 't', 'prompt'));
            self::fail('Missing capability accepted.');
        } catch (UnsupportedCapabilityException) {
            self::assertCount(1, $transport->requests);
        }
    }

    public function test_strict_message_is_negotiated(): void
    {
        [$client, $transport] = $this->setupClient(['strict_model' => true, 'detached_background' => true]);
        $transport->responses[] = ContractNegotiationTest::response(['status' => 'accepted', 'turn_id' => 't'], status: 202);
        $client->chats()->sendMessage('c', new ChatMessageRequest('hi', strictModel: true));
        self::assertTrue(json_decode($transport->requests[1]->body, true)['strict_model']);
    }

    public function test_unknown_task_stays_not_found_not_contract_failure(): void
    {
        [$client, $transport] = $this->setupClient(['detached_background']);
        $transport->responses[] = ContractNegotiationTest::response(['error' => ['type' => 'not_found', 'code' => 'task_unknown', 'message' => 'unknown outcome']], status: 404);
        $this->expectException(NotFoundException::class);
        $client->chats()->getTask('c', 'lost');
    }

    public function test_classifier_uncertain_result_is_never_retried(): void
    {
        [$client, $transport] = $this->setupClient(['phase_classification', 'detached_background']);
        $transport->responses[] = ContractNegotiationTest::response(['error' => ['type' => 'server_error', 'code' => 'classification_outcome_unknown', 'message' => 'uncertain']], status: 503);
        try {
            $client->chats()->classifyPhase('c', new PhaseClassificationRequest('r', true, 'phase', 1, 'output'));
            self::fail('Uncertain classification accepted.');
        } catch (ServerException) {
            self::assertCount(2, $transport->requests);
        }
    }
}
