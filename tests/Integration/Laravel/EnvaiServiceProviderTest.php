<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Exceptions\DefinitionsNotSynchronizedException;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiPlanCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiStateCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiStatusCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiSyncCommand;
use Digitalygo\Envai\Integration\Laravel\Console\EnvaiValidateCommand;
use Digitalygo\Envai\Integration\Laravel\EnvaiServiceProvider;
use Digitalygo\Envai\Sync\DefinitionsService;
use Digitalygo\Envai\Tests\Definitions\Tool\Fixtures\FakeEchoTool;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Console\Application;
use Illuminate\Container\Container;
use Illuminate\Database\Connectors\ConnectionFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;

require_once __DIR__.'/FrameworkHelpers.php';

/**
 * Registration and boot behavior of the Envai service provider over a
 * bare Illuminate Container: the per-connection client singletons, the
 * aliases, the default definitions binding wired through the `laravel`
 * state driver on the configured database connection, the publishes and
 * the command registration. Skipped cleanly when the Illuminate
 * packages are absent.
 */
final class EnvaiServiceProviderTest extends IntegrationTestCase
{
    private Container $app;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app = $this->buildApp();
    }

    public function test_negotiated_contract_and_replay_limit_configuration(): void
    {
        $this->app->make('config')->set('envai.connections.production.contract_version', 2);
        $this->app->make('config')->set('envai.connections.production.max_legacy_replay_bytes', 4096);
        $this->provider()->register();
        $client = $this->app->make('envai.client.production');
        $this->assertSame(2, $client->config()->contractVersion);
        $this->assertSame(4096, $client->config()->maxLegacyReplayBytes);
    }

    public function test_contract_configuration_rejects_numeric_strings(): void
    {
        $this->app->make('config')->set('envai.connections.production.contract_version', '3');
        $this->provider()->register();
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make('envai.client.production');
    }

    public function test_replay_limit_configuration_rejects_numeric_strings(): void
    {
        $this->app->make('config')->set('envai.connections.production.max_legacy_replay_bytes', '4096');
        $this->provider()->register();
        $this->expectException(\InvalidArgumentException::class);
        $this->app->make('envai.client.production');
    }

    public function test_register_merges_the_config_file(): void
    {
        $this->provider()->register();

        $config = $this->app->make('config');

        $this->assertSame('production', $config->get('envai.default'));
        $this->assertSame('deploy', $config->get('envai.definitions.sync_mode'));
        $this->assertSame('envai_sync_', $config->get('envai.state.table_prefix'));
    }

    public function test_register_binds_a_client_per_connection_and_the_aliases(): void
    {
        $this->provider()->register();

        $this->assertTrue($this->app->bound('envai.client.production'));
        $this->assertTrue($this->app->bound('envai.client'));
        $this->assertTrue($this->app->bound('envai'));

        $client = $this->app->make('envai.client.production');

        $this->assertInstanceOf(EnvaiClient::class, $client);
        $this->assertSame('production', $client->config()->connectionName);
        $this->assertSame('https://api.example.test', $client->config()->baseUrl);
        $this->assertSame('test-key', $client->config()->apiKey->reveal());
        $this->assertSame($client, $this->app->make('envai.client'));
        $this->assertSame($client, $this->app->make('envai'));
    }

    public function test_register_binds_the_default_definitions_service(): void
    {
        $this->provider()->register();

        $this->assertTrue($this->app->bound('envai.definitions'));
        $this->assertTrue($this->app->bound(DefinitionsService::class));

        $service = $this->app->make(DefinitionsService::class);

        $this->assertInstanceOf(DefinitionsService::class, $service);
        $this->assertSame($service, $this->app->make('envai.definitions'));
        $this->assertNotEmpty($service->manifest()->hash);

        // The wiring is real: a never-synchronized connection fails the
        // readiness gate through the configured state store.
        $this->expectException(DefinitionsNotSynchronizedException::class);

        $service->requireSynchronized();
    }

    public function test_register_resolves_the_laravel_state_driver_on_the_configured_connection(): void
    {
        $this->app->make('config')->set('envai.state.driver', 'laravel');
        $this->app->make('config')->set('envai.state.connection', 'default');
        $this->provider()->register();

        $service = $this->app->make('envai.definitions');

        $this->assertInstanceOf(DefinitionsService::class, $service);
        $this->assertNotEmpty($service->manifest()->hash);

        // The store lives on the configured database connection: the
        // manifests table of that connection exists (fixture) and the
        // readiness gate reports never-synchronized.
        $this->expectException(DefinitionsNotSynchronizedException::class);

        $service->requireSynchronized();
    }

    public function test_register_rejects_an_invalid_connection_config(): void
    {
        $this->app->make('config')->set('envai.connections.production.base_url', 'not-a-url');
        $this->provider()->register();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid Envai connection "production"');

        $this->app->make('envai.client.production');
    }

    public function test_boot_publishes_config_and_migrations_in_the_expected_groups(): void
    {
        $this->provider()->boot();

        $publishGroups = (new \ReflectionClass(EnvaiServiceProvider::class))->getStaticPropertyValue('publishGroups');

        $this->assertIsArray($publishGroups);
        $this->assertArrayHasKey('envai-config', $publishGroups);
        $this->assertArrayHasKey('envai-migrations', $publishGroups);

        // publishes() maps package source => publish target; the targets
        // are the second half of each entry.
        $configTargets = array_values($publishGroups['envai-config']);
        $migrationTargets = array_values($publishGroups['envai-migrations']);

        $this->assertStringEndsWith('envai.php', (string) $configTargets[0]);
        $this->assertStringEndsWith('migrations', (string) $migrationTargets[0]);
    }

    public function test_boot_registers_the_artisan_aliases(): void
    {
        // Illuminate 13 registers commands through the static
        // Application::starting() bootstrappers of the console
        // application; boot() must append exactly one bootstrapper that
        // hands the five alias classes to the artisan instance.
        $property = new \ReflectionProperty(Application::class, 'bootstrappers');
        $before = $property->getValue();

        $this->provider()->boot();

        $after = $property->getValue();
        $this->assertCount(count($before) + 1, $after);

        $artisan = new ArtisanCommandSpy;
        $after[array_key_last($after)]($artisan);

        $this->assertSame([
            EnvaiValidateCommand::class,
            EnvaiPlanCommand::class,
            EnvaiSyncCommand::class,
            EnvaiStatusCommand::class,
            EnvaiStateCommand::class,
        ], $artisan->commands);
    }

    public function test_boot_does_not_touch_the_database(): void
    {
        $schema = $this->app->make('db')->connection('default')->getSchemaBuilder();

        // The locks table is only ever created by the store on demand;
        // boot() must leave the database completely untouched.
        $this->assertFalse($schema->hasTable('envai_sync_locks'));

        $this->provider()->boot();

        $this->assertFalse($schema->hasTable('envai_sync_locks'));
    }

    private function provider(): EnvaiServiceProvider
    {
        return new EnvaiServiceProvider($this->app);
    }

    private function buildApp(): Container
    {
        $app = new Container;

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
                    'definitions' => [
                        'path' => $this->layout(),
                    ],
                ],
            ),
        ]);
        $app->instance('config', $config);

        $manager = new DatabaseManager($app, new ConnectionFactory($app));
        $app->instance('db', $manager);

        $schema = $manager->connection('default')->getSchemaBuilder();

        $schema->create('envai_sync_resources', function (Blueprint $table): void {
            $table->string('connection', 191);
            $table->string('resource_type', 64);
            $table->string('local_key', 191);
            $table->string('remote_id', 191)->nullable();
            $table->string('remote_name', 191)->nullable();
            $table->string('desired_hash', 191)->nullable();
            $table->text('last_applied_payload')->nullable();
            $table->text('remote_marker')->nullable();
            $table->string('status', 32);
            $table->string('synchronized_at', 64)->nullable();
            $table->string('created_at', 64);
            $table->string('updated_at', 64);
            $table->primary(['connection', 'resource_type', 'local_key']);
        });

        $schema->create('envai_sync_manifests', function (Blueprint $table): void {
            $table->string('connection', 191)->primary();
            $table->string('manifest_hash', 191);
            $table->string('synchronized_at', 64);
            $table->text('metadata');
        });

        return $app;
    }

    private function layout(): string
    {
        return $this->makeEnvaiLayout(
            agents: [
                'orchestrator' => $this->agentMarkdown('You orchestrate.', "format: 1\nname: orchestrator\nmode: primary\n"),
            ],
            teams: [
                'forecasting' => $this->teamPhp('forecasting', "\n    ->name('Forecasting team')\n    ->orchestrator('orchestrator')"),
            ],
            tools: $this->toolPhp('query_db', FakeEchoTool::class),
        );
    }
}

/**
 * Artisan command spy: the bootstrapper appended by boot() hands the
 * registered command classes to the artisan instance through
 * resolveCommands().
 */
final class ArtisanCommandSpy
{
    /**
     * @var list<class-string>
     */
    public array $commands = [];

    /**
     * @param  list<class-string>  $commands
     */
    public function resolveCommands(array $commands): void
    {
        $this->commands = $commands;
    }
}
