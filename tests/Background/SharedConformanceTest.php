<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Background;

use Digitalygo\Envai\Client\ChatsToolOutputSender;
use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Streaming\Atp\AtpEventDecoder;
use Digitalygo\Envai\Tests\Streaming\Atp\FakeEventStreamConnection;
use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tests\Tools\Support\RunnerHarness;
use Digitalygo\Envai\Tests\Wiki\ContractNegotiationTest;
use Digitalygo\Envai\Tests\Wiki\ContractTestTransport;
use Digitalygo\Envai\Tools\Execution\ClientToolRunner;
use Digitalygo\Envai\Tools\Execution\ToolRunOutcome;
use Digitalygo\Envai\Tools\State\FileClientToolCallStore;
use PHPUnit\Framework\TestCase;

final class SharedConformanceTest extends TestCase
{
    public function test_active_task_cancellation_is_cooperative_and_suppresses_late_output(): void
    {
        $fixtures = json_decode(file_get_contents(__DIR__.'/../fixtures/parity/fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
        foreach (['task_cancelled', 'turn_terminal'] as $terminal) {
            $tool = new FlexibleTool;
            $harness = new RunnerHarness(['fixture_echo' => FlexibleTool::class], [FlexibleTool::class => $tool]);
            $harness->transport->document = $fixtures['jwks'];
            $request = $fixtures['task_request'];
            $request['data']['event_payload']['args'] = (object) [];
            $event = (new AtpEventDecoder)->decode(json_encode($request));
            $barrier = (new AtpEventDecoder)->decode(json_encode($fixtures[$terminal]));
            $tool->onHandle = static function ($invocation) use ($harness, $barrier, $terminal): void {
                $harness->runner->observe($barrier);
                self::assertSame($terminal === 'task_cancelled', $invocation->cancellationToken->isCancelled());
                $invocation->cancellationToken->throwIfCancelled();
            };
            $result = $harness->runner->handleBackgroundEvent($event, $event->envelope->chatId);
            self::assertSame(1, $tool->invocations);
            self::assertSame($terminal === 'task_cancelled' ? ToolRunOutcome::Cancelled : ToolRunOutcome::Executed, $result->outcome);
            self::assertSame($terminal !== 'task_cancelled', $result->outputSubmitted);
        }
    }

    public function test_frozen_signed_scenarios_through_public_http_helpers(): void
    {
        $root = __DIR__.'/../fixtures/parity/';
        self::assertSame('ec969c671fae79c9cde6258555f207f9e09f4eb1b4058e966cf7ac359f5db255', hash_file('sha256', $root.'fixtures.json'));
        $fixtures = json_decode(file_get_contents($root.'fixtures.json'), true, flags: JSON_THROW_ON_ERROR);
        $scenarios = json_decode(file_get_contents($root.'scenarios.json'), flags: JSON_THROW_ON_ERROR);
        foreach ($scenarios->cases as $id => $case) {
            $native = ! str_starts_with($id, 'REPLAY-');
            $headers = ['x-envai-contract-version' => ['3']];
            if ($native) {
                $headers['x-envai-extensions'] = ['native-tasks-v1'];
            }
            $events = $case->events;
            $transport = new ContractTestTransport;
            $capabilities = ContractNegotiationTest::capabilities();
            $capabilities['features'][] = 'detached_background';
            $transport->responses[] = new HttpResponse(200, $headers, json_encode($capabilities));
            if (! $native) {
                $transport->responses[] = new HttpResponse(202, $headers, json_encode($scenarios->message_receipt));
                $terminal = json_decode(json_encode($fixtures['turn_terminal']));
                $terminal->seq = max(array_map(static fn ($event): int => $event->seq, $events)) + 1;
                $events[] = $terminal;
            }
            $transport->responses[] = new HttpResponse(204, $headers);
            $transport->connections[] = new FakeEventStreamConnection(array_map(static fn ($event): string => 'data: '.json_encode($event)."\n\n", $events), responseHeaders: $headers + ['content-type' => ['text/event-stream']]);
            $client = new EnvaiClient(new EnvaiConfig('https://example.test', new SensitiveString('synthetic'), nativeTasks: $native), $transport);
            $tool = new FlexibleTool;
            $harness = new RunnerHarness(['fixture_echo' => FlexibleTool::class], [FlexibleTool::class => $tool]);
            $harness->transport->document = $fixtures['jwks'];
            $path = sys_get_temp_dir().'/envai-conformance-'.bin2hex(random_bytes(8));
            try {
                $store = new FileClientToolCallStore($path);
                $runner = new ClientToolRunner($harness->registry, $harness->factory, $harness->verifier, $store, new ChatsToolOutputSender($client->chats()));
                if ($id === 'CANCEL-01') {
                    $cancelled = (new AtpEventDecoder)->decode(json_encode($events[2]));
                    $harness->transport->onFetch = static fn () => $runner->observe($cancelled);
                }
                try {
                    if ($native) {
                        $runner->handleBackgroundEvents($client->chat('w', $scenarios->execution_chat_id)->events(reconnect: false), $scenarios->execution_chat_id);
                    } else {
                        $runner->handleEvents($client->chat('w', $scenarios->execution_chat_id)->sendAndStream('synthetic', idempotencyKey: 'same-original-message'), $scenarios->execution_chat_id);
                    }
                    self::assertNotSame('SIGN-01', $id, 'Tampered signature was accepted.');
                } catch (ProtocolViolationException $exception) {
                    self::assertSame('SIGN-01', $id, $exception->getMessage());
                }
                self::assertSame($case->expected_effects, $tool->invocations, $id);
                $outputs = array_values(array_filter($transport->requests, static fn ($request): bool => str_ends_with($request->uri, '/tool-output')));
                self::assertCount($case->expected_submissions, $outputs, $id);
                foreach ($outputs as $request) {
                    self::assertStringContainsString('/chats/'.$scenarios->execution_chat_id.'/', $request->uri);
                    if ($native) {
                        self::assertSame(['native-tasks-v1'], $request->headers['x-envai-extensions']);
                    }
                }
            } finally {
                foreach (glob($path.'*') ?: [] as $file) {
                    unlink($file);
                }
            }
        }
    }
}
