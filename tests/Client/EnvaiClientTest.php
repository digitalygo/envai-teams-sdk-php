<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\AgentsService;
use Digitalygo\Envai\Client\ApiKeysService;
use Digitalygo\Envai\Client\CapabilitiesService;
use Digitalygo\Envai\Client\ChatsService;
use Digitalygo\Envai\Client\CompletionsService;
use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Client\ImagesService;
use Digitalygo\Envai\Client\ModelsService;
use Digitalygo\Envai\Client\TeamsService;
use Digitalygo\Envai\Client\ToolsService;
use Digitalygo\Envai\Client\WorkspaceFilesService;
use Digitalygo\Envai\Client\WorkspacesService;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Contracts\HttpTransport;
use Digitalygo\Envai\Http\Curl\CurlTransport;
use Digitalygo\Envai\Http\HttpRequest;
use Digitalygo\Envai\Http\HttpResponse;
use PHPUnit\Framework\TestCase;

/**
 * Construction and lazy service identity of EnvaiClient.
 */
final class EnvaiClientTest extends TestCase
{
    private static function config(): EnvaiConfig
    {
        return new EnvaiConfig(
            baseUrl: 'https://envai.example.com',
            apiKey: new SensitiveString('sk-test-secret'),
        );
    }

    public function test_lazy_service_getters_return_the_same_instance_on_second_call(): void
    {
        $client = new EnvaiClient(self::config(), new FakeTransport);

        $services = [
            'workspaces' => WorkspacesService::class,
            'tools' => ToolsService::class,
            'workspaceFiles' => WorkspaceFilesService::class,
            'chats' => ChatsService::class,
            'completions' => CompletionsService::class,
            'agents' => AgentsService::class,
            'teams' => TeamsService::class,
            'models' => ModelsService::class,
            'apiKeys' => ApiKeysService::class,
            'images' => ImagesService::class,
            'capabilities' => CapabilitiesService::class,
        ];

        foreach ($services as $getter => $class) {
            $first = $client->{$getter}();
            $this->assertInstanceOf($class, $first);

            $this->assertSame($first, $client->{$getter}(), sprintf('Service %s must be lazily cached and return the same instance.', $getter));
        }
    }

    public function test_custom_transport_injection_is_used(): void
    {
        $transport = new FakeTransport;
        $client = new EnvaiClient(self::config(), $transport);

        $this->assertSame($transport, $client->transport());
    }

    public function test_default_transport_is_curl(): void
    {
        $client = new EnvaiClient(self::config());

        $this->assertInstanceOf(CurlTransport::class, $client->transport());
    }

    public function test_config_is_exposed(): void
    {
        $config = self::config();
        $client = new EnvaiClient($config, new FakeTransport);

        $this->assertSame($config, $client->config());
    }

    public function test_transport_must_support_event_streaming(): void
    {
        $httpOnly = new class implements HttpTransport
        {
            public function send(HttpRequest $request): HttpResponse
            {
                return new HttpResponse(200);
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must also implement EventStreamTransport');

        new EnvaiClient(self::config(), $httpOnly);
    }

    public function test_requests_flow_through_the_injected_transport(): void
    {
        $transport = new FakeTransport;
        $transport->queueResponse(new HttpResponse(200, ['content-type' => ['application/json']], json_encode([
            'data' => ['status' => 'up', 'timestamp' => '2026-03-01T12:00:00+00:00', 'app' => 'Laravel', 'env' => 'local'],
        ], JSON_THROW_ON_ERROR)));

        $client = new EnvaiClient(self::config(), $transport);

        $health = $client->capabilities()->healthCheck();

        $this->assertSame('up', $health->status);
        $this->assertCount(1, $transport->requests);
        $this->assertSame('/api/v1/up', parse_url($transport->requests[0]->uri, PHP_URL_PATH));
        $this->assertArrayNotHasKey('authorization', $transport->requests[0]->headers, 'The public health check must not carry credentials.');
    }
}
