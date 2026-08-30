<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Digitalygo\Envai\Integration\Laravel\Actions\ReceiveEnvaiWebhook;
use Digitalygo\Envai\Integration\Laravel\EnvaiServiceProvider;
use Digitalygo\Envai\Integration\Laravel\Http\Middleware\RejectOversizedWebhookBody;
use Digitalygo\Envai\Webhooks\Contracts\WebhookResponsePolicy;
use Digitalygo\Envai\Webhooks\Contracts\WebhookVerifier;
use Digitalygo\Envai\Webhooks\WebhookManager;
use Digitalygo\Envai\Webhooks\WebhookPayload;
use Digitalygo\Envai\Webhooks\WebhookProcessingResult;
use Digitalygo\Envai\Webhooks\WebhookRequest;
use Digitalygo\Envai\Webhooks\WebhookVerificationResult;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\Repository;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;

require_once __DIR__.'/FrameworkHelpers.php';

final class EnvaiWebhookServiceProviderTest extends IntegrationTestCase
{
    private Container $app;

    private Router $router;

    private DatabaseManager $database;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = new Container;
        $config = new ConfigRepository([
            'database' => [
                'default' => 'default',
                'connections' => [
                    'default' => ['driver' => 'sqlite', 'database' => ':memory:'],
                ],
            ],
            'envai' => array_replace_recursive(
                require __DIR__.'/../../../config/envai.php',
                [
                    'connections' => [
                        'production' => [
                            'base_url' => 'https://api.example.test',
                            'api_key' => 'test-key',
                        ],
                    ],
                ],
            ),
        ]);
        $this->app->instance('config', $config);

        $this->database = new DatabaseManager($this->app, new ConnectionFactory($this->app));
        $this->app->instance('db', $this->database);

        $events = new Dispatcher($this->app);
        $this->app->instance('events', $events);

        $this->router = new Router($events, $this->app);
        $this->app->instance('router', $this->router);
        $this->app->instance(CacheRateLimiter::class, new CacheRateLimiter(new Repository(new ArrayStore)));

        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->app);
    }

    protected function tearDown(): void
    {
        if (class_exists(Facade::class)) {
            Facade::clearResolvedInstances();
            Facade::setFacadeApplication(null);
        }

        parent::tearDown();
    }

    public function test_disabled_webhooks_register_no_route_binding_or_limiter(): void
    {
        $this->configureWebhooks(['enabled' => false]);
        $provider = $this->provider();

        $provider->register();
        $provider->boot();

        $this->assertFalse($this->app->bound('envai.webhooks.manager'));
        $this->assertFalse($this->app->bound(RejectOversizedWebhookBody::class));
        $this->assertNull(RateLimiter::limiter('envai-webhooks'));
        $this->assertSame([], $this->router->getRoutes()->getRoutes());
    }

    public function test_enabled_webhooks_register_the_route_and_limiter_without_boot_database_access(): void
    {
        $this->configureWebhooks([
            'path' => 'api/custom/envai/webhooks',
            'max_body_bytes' => 2048,
            'rate_limit_per_minute' => 17,
        ]);
        $provider = $this->provider();
        $connection = $this->database->connection('default');
        $connection->enableQueryLog();

        $provider->register();
        $this->assertTrue($this->app->bound('envai.webhooks.manager'));
        $this->assertFalse($this->app->resolved('envai.webhooks.manager'));

        $provider->boot();

        $this->assertSame([], $connection->getQueryLog());
        $this->assertTrue($this->app->bound(ReceiveEnvaiWebhook::class));
        $this->assertTrue($this->app->bound(RejectOversizedWebhookBody::class));
        $this->assertInstanceOf(ReceiveEnvaiWebhook::class, $this->app->make(ReceiveEnvaiWebhook::class));

        $route = $this->webhookRoute();
        $this->assertSame(['POST'], $route->methods);
        $this->assertSame(ReceiveEnvaiWebhook::class.'@__invoke', $route->action['uses']);
        $this->assertSame([
            'api',
            RejectOversizedWebhookBody::class,
            'throttle:envai-webhooks',
        ], $route->middleware());

        $middleware = $this->app->make(RejectOversizedWebhookBody::class);
        $property = new \ReflectionProperty(RejectOversizedWebhookBody::class, 'maxBytes');
        $this->assertSame(2048, $property->getValue($middleware));

        $limiter = RateLimiter::limiter('envai-webhooks');
        $this->assertNotNull($limiter);
        $limit = $limiter(Request::create('/api/custom/envai/webhooks', 'POST'));
        $this->assertSame(17, $limit->maxAttempts);
        $this->assertSame(60, $limit->decaySeconds);
    }

    public function test_manager_is_lazy_and_resolves_the_configured_verifier_and_policy(): void
    {
        $this->configureWebhooks([
            'verifier' => ConfiguredWebhookVerifier::class,
            'response_policy' => ConfiguredWebhookResponsePolicy::class,
        ]);
        $verifier = new ConfiguredWebhookVerifier;
        $policy = new ConfiguredWebhookResponsePolicy;
        $this->app->instance(ConfiguredWebhookVerifier::class, $verifier);
        $this->app->instance(ConfiguredWebhookResponsePolicy::class, $policy);
        $provider = $this->provider();

        $provider->register();
        $provider->boot();

        $this->assertFalse($this->app->resolved('envai.webhooks.manager'));

        $manager = $this->app->make('envai.webhooks.manager');

        $this->assertInstanceOf(WebhookManager::class, $manager);
        $verifierProperty = new \ReflectionProperty(WebhookManager::class, 'verifier');
        $policyProperty = new \ReflectionProperty(WebhookManager::class, 'responsePolicy');
        $scopeProperty = new \ReflectionProperty(WebhookManager::class, 'scope');
        $this->assertSame($verifier, $verifierProperty->getValue($manager));
        $this->assertSame($policy, $policyProperty->getValue($manager));
        $this->assertSame('production', $scopeProperty->getValue($manager));
    }

    public static function invalidWebhookImplementations(): array
    {
        return [
            'missing verifier' => ['verifier', null, 'verifier'],
            'non-conforming verifier' => ['verifier', \stdClass::class, WebhookVerifier::class],
            'missing response policy' => ['response_policy', null, 'response_policy'],
            'non-conforming response policy' => ['response_policy', \stdClass::class, WebhookResponsePolicy::class],
        ];
    }

    #[DataProvider('invalidWebhookImplementations')]
    public function test_manager_resolution_fails_closed_for_invalid_implementations(string $key, mixed $value, string $message): void
    {
        $this->configureWebhooks([$key => $value]);
        $provider = $this->provider();

        $provider->register();
        $provider->boot();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);

        $this->app->make('envai.webhooks.manager');
    }

    public static function invalidWebhookPaths(): array
    {
        return [
            'empty' => [''],
            'space' => ['api/envai webhooks'],
            'control character' => ["api/envai\nwebhooks"],
            'double slash' => ['api//envai/webhooks'],
        ];
    }

    #[DataProvider('invalidWebhookPaths')]
    public function test_invalid_path_fails_closed_during_boot(string $path): void
    {
        $this->configureWebhooks(['path' => $path]);
        $provider = $this->provider();

        $provider->register();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('envai.webhooks.path');

        $provider->boot();
    }

    public static function invalidWebhookConfiguration(): array
    {
        return [
            'invalid table' => ['table', 'envai-receipts', 'envai.webhooks.table'],
            'zero body limit' => ['max_body_bytes', 0, 'envai.webhooks.max_body_bytes'],
            'string body limit' => ['max_body_bytes', '1048576', 'envai.webhooks.max_body_bytes'],
            'zero rate limit' => ['rate_limit_per_minute', 0, 'envai.webhooks.rate_limit_per_minute'],
            'missing connection' => ['connection', 'missing', 'envai.webhooks.connection'],
        ];
    }

    #[DataProvider('invalidWebhookConfiguration')]
    public function test_invalid_webhook_configuration_fails_closed_during_boot(string $key, mixed $value, string $message): void
    {
        $this->configureWebhooks([$key => $value]);
        $provider = $this->provider();

        $provider->register();

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage($message);

        $provider->boot();
    }

    private function configureWebhooks(array $overrides = []): void
    {
        $this->app->make('config')->set('envai.webhooks', array_replace([
            'enabled' => true,
            'path' => 'api/envai/webhooks',
            'verifier' => ConfiguredWebhookVerifier::class,
            'response_policy' => ConfiguredWebhookResponsePolicy::class,
            'connection' => null,
            'database_connection' => 'default',
            'table' => 'envai_webhook_receipts',
            'max_body_bytes' => 1048576,
            'rate_limit_per_minute' => 30,
        ], $overrides));
    }

    private function provider(): EnvaiServiceProvider
    {
        return new EnvaiServiceProvider($this->app);
    }

    private function webhookRoute(): Route
    {
        $routes = array_values(array_filter(
            $this->router->getRoutes()->getRoutes(),
            static fn (Route $route): bool => $route->uri === 'api/custom/envai/webhooks',
        ));

        $this->assertCount(1, $routes);

        return $routes[0];
    }
}

final class ConfiguredWebhookVerifier implements WebhookVerifier
{
    public function verify(WebhookRequest $request, WebhookPayload $payload): WebhookVerificationResult
    {
        return new WebhookVerificationResult('evt_configured', 'configured');
    }
}

final class ConfiguredWebhookResponsePolicy implements WebhookResponsePolicy
{
    public function responseFor(WebhookProcessingResult $result): object
    {
        return new \stdClass;
    }
}
