<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\ChatRuntime;
use Digitalygo\Envai\Client\ClientToolRuntime;
use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Client\WorkspaceScope;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Exceptions\DefinitionsNotSynchronizedException;
use Digitalygo\Envai\Http\HttpResponse;
use Digitalygo\Envai\Models\Chat\Chat;
use Digitalygo\Envai\Models\Chat\ChatCreateRequest;
use Digitalygo\Envai\Tests\Tools\Support\FlexibleTool;
use Digitalygo\Envai\Tools\Execution\ClientToolRegistry;
use PHPUnit\Framework\TestCase;

final class ClientWiringTest extends TestCase
{
    private function client(EnvaiConfig $config, FakeTransport $transport): EnvaiClient
    {
        return new EnvaiClient($config, $transport);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function config(array $overrides = []): EnvaiConfig
    {
        return new EnvaiConfig(...array_merge([
            'baseUrl' => 'https://envai.example.com',
            'apiKey' => new SensitiveString('sk-test-secret'),
            'connectionName' => 'default-conn',
            'stateDriver' => 'inmemory',
        ], $overrides));
    }

    public function test_definitions_returns_the_same_cached_instance(): void
    {
        $client = $this->client($this->config(['definitionsPath' => $this->layout()]), new FakeTransport);

        $first = $client->definitions();
        $second = $client->definitions();

        $this->assertSame($first, $second);
    }

    public function test_definitions_defaults_to_the_configured_connection(): void
    {
        $client = $this->client($this->config(['definitionsPath' => $this->layout()]), new FakeTransport);

        try {
            $client->definitions()->requireSynchronized();
            $this->fail('requireSynchronized must throw before any sync.');
        } catch (DefinitionsNotSynchronizedException $exception) {
            $this->assertStringContainsString('default-conn', $exception->getMessage());
        }
    }

    public function test_definitions_with_explicit_connection_is_a_distinct_instance(): void
    {
        $client = $this->client($this->config(['definitionsPath' => $this->layout()]), new FakeTransport);

        $default = $client->definitions();
        $staging = $client->definitions(connection: 'staging');
        $stagingAgain = $client->definitions(connection: 'staging');

        $this->assertNotSame($default, $staging);
        $this->assertSame($staging, $stagingAgain);

        try {
            $staging->requireSynchronized();
            $this->fail('requireSynchronized must throw before any sync.');
        } catch (DefinitionsNotSynchronizedException $exception) {
            $this->assertStringContainsString('staging', $exception->getMessage());
        }
    }

    public function test_workspace_is_cached_per_id_and_chats_create_with_team_named_argument(): void
    {
        $transport = new FakeTransport;
        $client = $this->client($this->config(), $transport);

        $scope = $client->workspace('wsp_01');
        $again = $client->workspace('wsp_01');

        $this->assertSame($scope, $again);
        $this->assertInstanceOf(WorkspaceScope::class, $scope);

        $transport->queueResponse($this->envelopeResponse([
            'id' => 'cht_01',
            'workspace_id' => 'wsp_01',
            'user_id' => 'usr_01',
            'team_id' => 'team_forecasting',
            'created_at' => '2026-08-28T10:00:00Z',
            'updated_at' => '2026-08-28T10:00:00Z',
        ]));

        $chat = $scope->chats()->create(team: 'team_forecasting');

        $this->assertInstanceOf(Chat::class, $chat);
        $this->assertSame('cht_01', $chat->id);
        $this->assertSame('team_forecasting', $chat->teamId);

        $request = $transport->requests[0];

        $this->assertSame('POST', $request->method);
        $this->assertSame('/api/v1/workspaces/wsp_01/chats', parse_url($request->uri, PHP_URL_PATH));
        $this->assertSame(['team_id' => 'team_forecasting'], json_decode((string) $request->body, true, 512, JSON_THROW_ON_ERROR));
    }

    public function test_chats_create_accepts_a_chat_create_request_too(): void
    {
        $transport = new FakeTransport;
        $client = $this->client($this->config(), $transport);

        $transport->queueResponse($this->envelopeResponse([
            'id' => 'cht_02',
            'workspace_id' => 'wsp_01',
            'user_id' => 'usr_01',
            'team_id' => 'team_forecasting',
            'created_at' => '2026-08-28T10:00:00Z',
            'updated_at' => '2026-08-28T10:00:00Z',
        ]));

        $chat = $client->workspace('wsp_01')->chats()->create(new ChatCreateRequest('team_forecasting'));

        $this->assertSame('cht_02', $chat->id);
    }

    public function test_chat_runtime_is_cached_per_workspace_and_chat_id(): void
    {
        $client = $this->client($this->config(), new FakeTransport);

        $first = $client->chat('wsp_01', 'cht_01');
        $second = $client->chat('wsp_01', 'cht_01');
        $other = $client->chat('wsp_02', 'cht_01');

        $this->assertInstanceOf(ChatRuntime::class, $first);
        $this->assertSame($first, $second);
        $this->assertNotSame($first, $other);
    }

    public function test_client_tools_is_cached_per_registry(): void
    {
        $client = $this->client($this->config(), new FakeTransport);

        $registry = ClientToolRegistry::fromArray(['echo' => FlexibleTool::class]);

        $first = $client->clientTools($registry);
        $second = $client->clientTools($registry);

        $this->assertInstanceOf(ClientToolRuntime::class, $first);
        $this->assertSame($first, $second);
        $this->assertSame($first->runner(), $first->runner());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function envelopeResponse(array $payload): HttpResponse
    {
        return new HttpResponse(200, ['content-type' => ['application/json']], json_encode(['data' => $payload], JSON_THROW_ON_ERROR));
    }

    private function layout(): string
    {
        $dir = sys_get_temp_dir().'/atp-wiring-'.bin2hex(random_bytes(4));
        mkdir($dir.'/agents', 0777, true);
        mkdir($dir.'/teams', 0777, true);
        file_put_contents($dir.'/agents/orchestrator.md', "---\nformat: 1\nname: orchestrator\nmode: primary\n---\nYou orchestrate.\n");
        file_put_contents($dir.'/teams/forecasting.php', "<?php\n\nuse Digitalygo\\Envai\\Definitions\\Team;\n\nreturn Team::make('forecasting')->name('Forecasting team')->orchestrator('orchestrator');\n");

        return $dir;
    }
}
