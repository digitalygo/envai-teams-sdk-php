<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Integration\Laravel\Actions\ReceiveEnvaiWebhook;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiPlanCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiStateCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiStatusCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiSyncCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiValidateCommand;
use Digitalygo\Envai\Integration\Laravel\Http\Middleware\RejectOversizedWebhookBody;
use Digitalygo\Envai\Integration\Laravel\WebhookDispatcher\LaravelWebhookEventDispatcher;
use Digitalygo\Envai\Integration\Laravel\WebhookIdempotency\LaravelDatabaseWebhookIdempotencyStore;
use Digitalygo\Envai\Sync\Client\StateStoreFactory;
use Digitalygo\Envai\Sync\DefinitionsService;
use Digitalygo\Envai\Sync\State\SyncStateStore;
use Digitalygo\Envai\Webhooks\Contracts\WebhookResponsePolicy;
use Digitalygo\Envai\Webhooks\Contracts\WebhookVerifier;
use Digitalygo\Envai\Webhooks\WebhookManager;
use Digitalygo\Envai\Webhooks\WebhookValidator;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Optional Laravel integration provider.
 *
 * register() merges config/envai.php under the `envai` key and binds:
 *
 * - `envai.client.<connection>`: one EnvaiClient singleton per
 *   configured connection, built from config('envai.connections.<name>');
 * - `envai.client` and the `envai` alias: the default connection's
 *   client;
 * - `envai.definitions` and `DefinitionsService::class`: the default
 *   definitions facade, built lazily through the client and wired with a
 *   state store resolver that understands the `laravel` driver
 *   (LaravelDatabaseSyncStateStore on the configured state connection)
 *   and falls back to StateStoreFactory for the core drivers.
 *
 * boot() publishes the config file (group `envai-config`) and the
 * migration (group `envai-migrations`) and registers the Artisan
 * aliases.
 *
 * The provider never synchronizes, opens streams, creates tables or
 * imposes a database at boot time: state table creation belongs to the
 * published migration and the store's lazy locks-table bootstrap, both
 * driven by the consumer's schedule. All bindings are lazy: nothing
 * touches the network or the database until a service is resolved.
 *
 * The provider (and every class under this namespace) is only loadable
 * when the Illuminate packages are present; the core package never
 * requires it.
 */
final class EnvaiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! Compatibility::illuminateAvailable()) {
            return;
        }

        $this->mergeConfigFrom(__DIR__.'/../../../config/envai.php', 'envai');

        $stateStoreResolver = $this->stateStoreResolver();

        $config = $this->configRepository($this->app);

        foreach (array_keys((array) $config->get('envai.connections', [])) as $connection) {
            if (! is_string($connection) || $connection === '') {
                continue;
            }

            $this->app->singleton('envai.client.'.$connection, fn (Container $app): EnvaiClient => $this->client($connection, $stateStoreResolver));
        }

        $defaultConnection = $this->defaultConnection($config);

        $this->app->singleton('envai.client', fn (Container $app): EnvaiClient => $this->requireClient(
            $app->make('envai.client.'.$defaultConnection),
            'envai.client.'.$defaultConnection,
        ));
        $this->app->alias('envai.client', 'envai');

        $this->app->singleton('envai.definitions', function (Container $app) use ($stateStoreResolver): DefinitionsService {
            $config = $this->configRepository($app);

            $connection = $this->defaultConnection($config);
            $path = $this->stringConfig($config, 'envai.definitions.path', 'envai');

            return $this->requireClient(
                $app->make('envai.client.'.$connection),
                'envai.client.'.$connection,
            )->definitions($path, $connection, $stateStoreResolver);
        });
        $this->app->alias('envai.definitions', DefinitionsService::class);

        if ($config->get('envai.webhooks.enabled') === true) {
            $this->registerWebhookBindings();
        }
    }

    public function boot(): void
    {
        if (! Compatibility::illuminateAvailable()) {
            return;
        }

        if ($this->runningInConsole($this->app)) {
            $this->publishes([
                __DIR__.'/../../../config/envai.php' => function_exists('config_path') ? config_path('envai.php') : __DIR__.'/../../../config/envai.php',
            ], 'envai-config');

            $this->publishes([
                __DIR__.'/../../../database/migrations/' => function_exists('database_path') ? database_path('migrations') : __DIR__.'/../../../database/migrations/',
            ], 'envai-migrations');
        }

        $config = $this->configRepository($this->app);

        if ($config->get('envai.webhooks.enabled') === true) {
            $webhookConfig = $this->webhookConfig($config);

            $this->registerWebhookRateLimiter($webhookConfig['rate_limit_per_minute']);
            $this->loadRoutesFrom(__DIR__.'/routes/envai-webhooks.php');
        }

        $this->commands([
            EnvaiValidateCommand::class,
            EnvaiPlanCommand::class,
            EnvaiSyncCommand::class,
            EnvaiStatusCommand::class,
            EnvaiStateCommand::class,
        ]);
    }

    private function registerWebhookBindings(): void
    {
        $this->app->singleton('envai.webhooks.manager', fn (Container $app): WebhookManager => $this->webhookManager($app));
        $this->app->bind(RejectOversizedWebhookBody::class, fn (Container $app): RejectOversizedWebhookBody => new RejectOversizedWebhookBody(
            $this->webhookConfig($this->configRepository($app))['max_body_bytes'],
        ));
        $this->app->bind(ReceiveEnvaiWebhook::class, fn (Container $app): ReceiveEnvaiWebhook => new ReceiveEnvaiWebhook($app));
    }

    private function registerWebhookRateLimiter(int $maxAttempts): void
    {
        RateLimiter::for('envai-webhooks', static fn (Request $request): Limit => Limit::perMinute($maxAttempts)->by($request->ip() ?? 'unknown'));
    }

    private function webhookManager(Container $app): WebhookManager
    {
        $config = $this->configRepository($app);
        $webhookConfig = $this->webhookConfig($config);
        $verifier = $this->resolveWebhookVerifier($app, $config);
        $responsePolicy = $this->resolveWebhookResponsePolicy($app, $config);
        $databaseManager = $this->databaseManager();
        $events = $this->eventsDispatcher($app);

        return new WebhookManager(
            new WebhookValidator($webhookConfig['max_body_bytes']),
            $verifier,
            new LaravelDatabaseWebhookIdempotencyStore(
                $databaseManager->connection($webhookConfig['database_connection']),
                $webhookConfig['table'],
            ),
            new LaravelWebhookEventDispatcher($events, $webhookConfig['connection']),
            $responsePolicy,
            $webhookConfig['connection'],
        );
    }

    private function resolveWebhookVerifier(Container $app, ConfigRepository $config): WebhookVerifier
    {
        $implementation = $this->resolveWebhookImplementation(
            $app,
            $config->get('envai.webhooks.verifier'),
            'verifier',
            WebhookVerifier::class,
        );

        if (! $implementation instanceof WebhookVerifier) {
            throw new \LogicException('The Envai webhook verifier must implement '.WebhookVerifier::class.'.');
        }

        return $implementation;
    }

    private function resolveWebhookResponsePolicy(Container $app, ConfigRepository $config): WebhookResponsePolicy
    {
        $implementation = $this->resolveWebhookImplementation(
            $app,
            $config->get('envai.webhooks.response_policy'),
            'response_policy',
            WebhookResponsePolicy::class,
        );

        if (! $implementation instanceof WebhookResponsePolicy) {
            throw new \LogicException('The Envai webhook response policy must implement '.WebhookResponsePolicy::class.'.');
        }

        return $implementation;
    }

    private function resolveWebhookImplementation(
        Container $app,
        mixed $configuredClass,
        string $configurationKey,
        string $contract,
    ): object {
        if (! is_string($configuredClass) || $configuredClass === '') {
            throw new \LogicException(sprintf(
                'The Envai webhook %s must be a non-empty class-string implementing %s.',
                $configurationKey,
                $contract,
            ));
        }

        if (! class_exists($configuredClass) || ! is_a($configuredClass, $contract, true)) {
            throw new \LogicException(sprintf(
                'The Envai webhook %s class "%s" must implement %s.',
                $configurationKey,
                $configuredClass,
                $contract,
            ));
        }

        try {
            $implementation = $app->make($configuredClass);
        } catch (\Throwable $exception) {
            throw new \LogicException(sprintf(
                'The Envai webhook %s class "%s" could not be resolved.',
                $configurationKey,
                $configuredClass,
            ), previous: $exception);
        }

        if (! is_object($implementation) || ! is_a($implementation, $contract)) {
            throw new \LogicException(sprintf(
                'The Envai webhook %s binding must resolve to an implementation of %s.',
                $configurationKey,
                $contract,
            ));
        }

        return $implementation;
    }

    private function eventsDispatcher(Container $app): Dispatcher
    {
        try {
            $dispatcher = $app->make('events');
        } catch (\Throwable $exception) {
            throw new \LogicException('The container "events" binding must resolve to an Illuminate event dispatcher.', previous: $exception);
        }

        if (! $dispatcher instanceof Dispatcher) {
            throw new \LogicException('The container "events" binding must resolve to an Illuminate event dispatcher.');
        }

        return $dispatcher;
    }

    private function webhookConfig(ConfigRepository $config): array
    {
        $webhooks = $config->get('envai.webhooks');

        if (! is_array($webhooks)) {
            throw new \LogicException('The envai.webhooks configuration must be an array.');
        }

        $path = $webhooks['path'] ?? null;

        if (! is_string($path) || $path === '' || preg_match('/[\\s\\x00-\\x1F\\x7F]/', $path) === 1 || str_contains($path, '//')) {
            throw new \LogicException('The envai.webhooks.path configuration must be a non-empty path without spaces, control characters or double slashes.');
        }

        $table = $webhooks['table'] ?? null;

        if (! is_string($table) || $table === '' || preg_match('/\A[A-Za-z0-9_]+\z/D', $table) !== 1) {
            throw new \LogicException('The envai.webhooks.table configuration must be a non-empty SQL identifier.');
        }

        $maxBodyBytes = $webhooks['max_body_bytes'] ?? null;

        if (! is_int($maxBodyBytes) || $maxBodyBytes < 1) {
            throw new \LogicException('The envai.webhooks.max_body_bytes configuration must be a positive integer.');
        }

        $rateLimitPerMinute = $webhooks['rate_limit_per_minute'] ?? null;

        if (! is_int($rateLimitPerMinute) || $rateLimitPerMinute < 1) {
            throw new \LogicException('The envai.webhooks.rate_limit_per_minute configuration must be a positive integer.');
        }

        $configuredConnection = $webhooks['connection'] ?? null;

        if ($configuredConnection !== null && (! is_string($configuredConnection) || trim($configuredConnection) === '' || preg_match('/[\\r\\n\\x00-\\x1F\\x7F]/', $configuredConnection) === 1)) {
            throw new \LogicException('The envai.webhooks.connection configuration must be null or a non-empty safe connection name.');
        }

        $connection = $configuredConnection ?? $this->defaultConnection($config);

        if (trim($connection) === '' || preg_match('/[\r\n\x00-\x1F\x7F]/', $connection) === 1) {
            throw new \LogicException('The envai.webhooks.connection configuration must resolve to a non-empty safe connection name.');
        }

        $connections = $config->get('envai.connections', []);

        if (! is_array($connections) || ! array_key_exists($connection, $connections)) {
            throw new \LogicException(sprintf(
                'The envai.webhooks.connection "%s" is not configured in envai.connections.',
                $connection,
            ));
        }

        $databaseConnection = $webhooks['database_connection'] ?? null;

        if ($databaseConnection !== null && (! is_string($databaseConnection) || trim($databaseConnection) === '' || preg_match('/[\\r\\n\\x00-\\x1F\\x7F]/', $databaseConnection) === 1)) {
            throw new \LogicException('The envai.webhooks.database_connection configuration must be null or a non-empty safe connection name.');
        }

        return [
            'path' => $path,
            'connection' => $connection,
            'database_connection' => $databaseConnection,
            'table' => $table,
            'max_body_bytes' => $maxBodyBytes,
            'rate_limit_per_minute' => $rateLimitPerMinute,
        ];
    }

    /**
     * The state store resolver passed to every client: the `laravel`
     * driver maps onto LaravelDatabaseSyncStateStore over the configured
     * state connection (config('envai.state.connection'), defaulting to
     * the application's default database connection) with the configured
     * table prefix; every other driver goes through StateStoreFactory,
     * so the core `inmemory`, `file` and `pdo` drivers keep working
     * unchanged.
     *
     * @return \Closure(string $driver, array<string, mixed> $options): SyncStateStore
     */
    private function stateStoreResolver(): \Closure
    {
        return function (string $driver, array $options): SyncStateStore {
            if (strtolower($driver) !== 'laravel') {
                $normalized = [];

                foreach ($options as $key => $value) {
                    if (is_string($key)) {
                        $normalized[$key] = $value;
                    }
                }

                return (new StateStoreFactory)->create($driver, $normalized);
            }

            $db = $this->databaseManager();
            $config = $this->configRepository($this->app);

            $connection = $this->stringConfig($config, 'envai.state.connection', $db->getDefaultConnection()) ?? $db->getDefaultConnection();
            $prefix = $this->stringConfig($config, 'envai.state.table_prefix', 'envai_sync_') ?? 'envai_sync_';

            return new LaravelDatabaseSyncStateStore(
                $db->connection($connection),
                $prefix,
            );
        };
    }

    private function client(string $connection, \Closure $stateStoreResolver): EnvaiClient
    {
        $config = $this->configRepository($this->app);
        $connectionConfig = (array) $config->get('envai.connections.'.$connection, []);

        $baseUrl = $connectionConfig['base_url'] ?? '';
        $apiKey = $connectionConfig['api_key'] ?? '';
        $contractVersion = $connectionConfig['contract_version'] ?? 3;
        $maxLegacyReplayBytes = $connectionConfig['max_legacy_replay_bytes'] ?? 67_108_864;
        if (! is_int($maxLegacyReplayBytes)) {
            throw new \InvalidArgumentException('max_legacy_replay_bytes must be a positive integer.');
        }
        if (! is_int($contractVersion)) {
            throw new \InvalidArgumentException('contract_version must be the integer 2 or 3.');
        }

        try {
            return new EnvaiClient(
                new EnvaiConfig(
                    baseUrl: is_string($baseUrl) ? $baseUrl : '',
                    apiKey: new SensitiveString(is_string($apiKey) ? $apiKey : ''),
                    connectionName: $connection,
                    contractVersion: $contractVersion,
                    maxLegacyReplayBytes: $maxLegacyReplayBytes,
                    definitionsPath: $this->stringConfig($config, 'envai.definitions.path', null),
                    stateDriver: $this->stringConfig($config, 'envai.state.driver', 'file'),
                    stateFilePath: $this->stringConfig($config, 'envai.state.file', null),
                ),
                stateStoreResolver: $stateStoreResolver,
            );
        } catch (\InvalidArgumentException $exception) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid Envai connection "%s": %s',
                $connection,
                $exception->getMessage(),
            ), previous: $exception);
        }
    }

    private function defaultConnection(ConfigRepository $config): string
    {
        return $this->stringConfig($config, 'envai.default', 'production') ?? 'production';
    }

    /**
     * Resolves the application config repository through the `config`
     * binding (the key every Laravel application binds; the interface
     * class is an alias for it there) and validates the result, so a
     * misconfigured container fails with a clear message.
     */
    private function configRepository(object $app): ConfigRepository
    {
        if (! method_exists($app, 'make')) {
            throw new \LogicException('The Envai service provider requires a container exposing make().');
        }

        $config = $app->make('config');

        if (! $config instanceof ConfigRepository) {
            throw new \LogicException('The container "config" binding must implement Illuminate\\Contracts\\Config\\Repository.');
        }

        return $config;
    }

    /**
     * Whether the application is running in console mode. Containers
     * without runningInConsole() (tests on a bare Container) are treated
     * as console, so publishes are always registered in that context.
     */
    private function runningInConsole(object $app): bool
    {
        return ! method_exists($app, 'runningInConsole') || $app->runningInConsole();
    }

    /**
     * Resolves the application database manager through the `db`
     * binding and validates the result.
     */
    private function databaseManager(): DatabaseManager
    {
        $db = $this->app->make('db');

        if (! $db instanceof DatabaseManager) {
            throw new \LogicException('The container "db" binding must be an Illuminate\\Database\\DatabaseManager.');
        }

        return $db;
    }

    /**
     * Validates a resolved client binding before it is used.
     */
    private function requireClient(mixed $client, string $binding): EnvaiClient
    {
        if (! $client instanceof EnvaiClient) {
            throw new \LogicException(sprintf('The container binding "%s" must resolve to an EnvaiClient.', $binding));
        }

        return $client;
    }

    private function stringConfig(ConfigRepository $config, string $key, ?string $default): ?string
    {
        $value = $config->get($key);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
