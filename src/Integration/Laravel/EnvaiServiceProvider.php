<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiPlanCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiStateCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiStatusCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiSyncCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiValidateCommand;
use Digitalygo\Envai\Sync\Client\StateStoreFactory;
use Digitalygo\Envai\Sync\DefinitionsService;
use Digitalygo\Envai\Sync\State\SyncStateStore;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\ServiceProvider;

/**
 * Optional Laravel integration provider (project document section 25).
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

        $this->commands([
            EnvaiValidateCommand::class,
            EnvaiPlanCommand::class,
            EnvaiSyncCommand::class,
            EnvaiStatusCommand::class,
            EnvaiStateCommand::class,
        ]);
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

        try {
            return new EnvaiClient(
                new EnvaiConfig(
                    baseUrl: is_string($baseUrl) ? $baseUrl : '',
                    apiKey: new SensitiveString(is_string($apiKey) ? $apiKey : ''),
                    connectionName: $connection,
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
