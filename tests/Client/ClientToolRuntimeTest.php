<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\ProtocolViolationException;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tests\Tools\Support\TestKeys;
use Digitalygo\Envai\Tests\Tools\Support\ToolRequestFactory;
use Digitalygo\Envai\Tools\Execution\ClientToolRegistry;
use Digitalygo\Envai\Tools\Execution\ToolRunOutcome;
use Digitalygo\Envai\Tools\State\ClientToolCallStatus;
use PHPUnit\Framework\TestCase;

final class ClientToolRuntimeTest extends TestCase
{
    public function test_runner_executes_a_signed_request_end_to_end(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid(1);

        $transport = new FakeTransport;
        $transport->queueResponse($this->jsonResponse(TestKeys::jwkDocument($kid, $keys['public'])));
        $transport->queueResponse(new HttpResponse(204));

        $client = $this->client($transport);
        $registry = ClientToolRegistry::fromArray(['lookup_order' => FlexibleTool::class]);

        $event = ToolRequestFactory::signed('call_01', 'lookup_order', ['order_id' => 'ord_123'], $keys['secret'], $kid, chatId: 'cht_01');

        $result = $client->clientTools($registry)->runner()->handle($event, 'cht_01');

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertTrue($result->outputSubmitted);
        $this->assertSame(ClientToolCallStatus::Resolved, $result->storeStatus);
        $this->assertNull($result->error);

        // The JWKS was fetched through the client transport.
        $jwksRequest = $transport->requests[0];

        $this->assertSame('GET', $jwksRequest->method);
        $this->assertSame('/.well-known/jwks.json', parse_url($jwksRequest->uri, PHP_URL_PATH));

        // The output was delivered to the chat tool-output endpoint.
        $outputRequest = $transport->requests[1];

        $this->assertSame('POST', $outputRequest->method);
        $this->assertSame('/api/v1/atp/v3/chats/cht_01/tool-output', parse_url($outputRequest->uri, PHP_URL_PATH));

        $body = json_decode((string) $outputRequest->body, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('call_01', $body['call_id']);
        $this->assertSame('ok', $body['output']);
        $this->assertFalse($body['is_error']);
    }

    public function test_handle_events_filters_and_runs_tool_requests(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid(1);

        $transport = new FakeTransport;
        $transport->queueResponse($this->jsonResponse(TestKeys::jwkDocument($kid, $keys['public'])));
        $transport->queueResponse(new HttpResponse(204));

        $client = $this->client($transport);
        $registry = ClientToolRegistry::fromArray(['lookup_order' => FlexibleTool::class]);

        $event = ToolRequestFactory::signed('call_02', 'lookup_order', ['order_id' => 'ord_456'], $keys['secret'], $kid, chatId: 'cht_02');

        $results = $client->clientTools($registry)->handleEvents([$event], 'cht_02');

        $this->assertCount(1, $results);
        $this->assertSame('call_02', $results[0]->callId);
        $this->assertSame(ToolRunOutcome::Executed, $results[0]->outcome);
        $this->assertSame('/api/v1/atp/v3/chats/cht_02/tool-output', parse_url($transport->requests[1]->uri, PHP_URL_PATH));
    }

    public function test_unknown_tool_delivers_a_controlled_error(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid(1);

        $transport = new FakeTransport;
        $transport->queueResponse($this->jsonResponse(TestKeys::jwkDocument($kid, $keys['public'])));
        $transport->queueResponse(new HttpResponse(204));

        $client = $this->client($transport);
        $registry = ClientToolRegistry::fromArray(['lookup_order' => FlexibleTool::class]);

        $event = ToolRequestFactory::signed('call_03', 'not_registered', ['a' => 1], $keys['secret'], $kid, chatId: 'cht_03');

        $result = $client->clientTools($registry)->runner()->handle($event, 'cht_03');

        $this->assertSame(ToolRunOutcome::UnknownTool, $result->outcome);
        $this->assertTrue($result->outputSubmitted);

        $body = json_decode((string) $transport->requests[1]->body, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('call_03', $body['call_id']);
        $this->assertTrue($body['is_error']);
        $this->assertSame('tool_not_found', json_decode($body['output'], true, 512, JSON_THROW_ON_ERROR)['code']);
    }

    public function test_unverified_request_throws_and_never_submits(): void
    {
        $keys = TestKeys::generate();

        $transport = new FakeTransport;
        $transport->queueResponse($this->jsonResponse(TestKeys::jwkDocument(TestKeys::kid(1), $keys['public'])));

        $client = $this->client($transport);
        $registry = ClientToolRegistry::fromArray(['lookup_order' => FlexibleTool::class]);

        // Signed with a different key: verification must fail closed.
        $other = TestKeys::generate();
        $event = ToolRequestFactory::signed('call_04', 'lookup_order', ['order_id' => 'ord_789'], $other['secret'], TestKeys::kid(1), chatId: 'cht_04');

        $this->expectException(ProtocolViolationException::class);

        $client->clientTools($registry)->runner()->handle($event, 'cht_04');
    }

    public function test_registry_is_compiled_from_the_local_definitions_when_not_given(): void
    {
        $keys = TestKeys::generate();
        $kid = TestKeys::kid(1);

        $dir = sys_get_temp_dir().'/atp-ctr-'.bin2hex(random_bytes(4));
        mkdir($dir.'/agents', 0777, true);
        mkdir($dir.'/teams', 0777, true);
        file_put_contents($dir.'/agents/orchestrator.md', "---\nformat: 1\nname: orchestrator\nmode: primary\n---\nYou orchestrate.\n");
        file_put_contents($dir.'/teams/forecasting.php', "<?php\n\nuse Digitalygo\\Envai\\Definitions\\Team;\n\nreturn Team::make('forecasting')->name('Forecasting team')->orchestrator('orchestrator');\n");
        file_put_contents($dir.'/tools.php', "<?php\n\nreturn ['lookup_order' => \\Digitalygo\\Envai\\Tests\\Tools\\Support\\FlexibleTool::class];\n");

        $transport = new FakeTransport;
        $transport->queueResponse($this->jsonResponse(TestKeys::jwkDocument($kid, $keys['public'])));
        $transport->queueResponse(new HttpResponse(204));

        $config = new EnvaiConfig(
            baseUrl: 'https://envai.example.com',
            apiKey: new SensitiveString('sk-test-secret'),
            stateDriver: 'inmemory',
            definitionsPath: $dir,
        );

        $client = new EnvaiClient($config, $transport);

        $event = ToolRequestFactory::signed('call_05', 'lookup_order', ['order_id' => 'ord_111'], $keys['secret'], $kid, chatId: 'cht_05');

        $result = $client->clientTools()->runner()->handle($event, 'cht_05');

        $this->assertSame(ToolRunOutcome::Executed, $result->outcome);
        $this->assertTrue($result->outputSubmitted);
    }

    private function client(FakeTransport $transport): EnvaiClient
    {
        $config = new EnvaiConfig(
            baseUrl: 'https://envai.example.com',
            apiKey: new SensitiveString('sk-test-secret'),
        );

        return new EnvaiClient($config, $transport);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function jsonResponse(array $payload): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => ['application/json']], json_encode($payload, JSON_THROW_ON_ERROR));
    }
}
