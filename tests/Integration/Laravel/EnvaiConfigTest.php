<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Integration\Laravel;

use function env;

/**
 * Shape test of the publishable config/envai.php stub: the exact `default`, `connections`, `definitions` and
 * `state` keys with their environment-driven defaults. The file calls
 * env() (an Illuminate helper) and base_path(), so this test runs in the
 * throwaway Laravel worktree and skips elsewhere.
 */
final class EnvaiConfigTest extends IntegrationTestCase
{
    /**
     * @return array<string, mixed>
     */
    private function config(): array
    {
        $config = require __DIR__.'/../../../config/envai.php';

        $this->assertIsArray($config);

        return $config;
    }

    public function test_shape_has_the_documented_top_level_keys(): void
    {
        $config = $this->config();

        $this->assertSame(['default', 'connections', 'definitions', 'state', 'webhooks'], array_keys($config));
    }

    public function test_default_connection_is_production(): void
    {
        $this->assertSame('production', $this->config()['default']);
    }

    public function test_connections_shape_has_base_url_and_api_key(): void
    {
        $connections = $this->config()['connections'];

        $this->assertIsArray($connections);
        $this->assertArrayHasKey('production', $connections);

        $production = $connections['production'];

        $this->assertSame(['base_url', 'api_key', 'contract_version', 'native_tasks', 'strict_validation', 'jwks_url', 'headers', 'connect_timeout_seconds', 'request_timeout_seconds', 'stream_timeout_seconds', 'retry_policy', 'max_legacy_replay_bytes'], array_keys($production));
        $this->assertSame(env('ENVAI_BASE_URL'), $production['base_url']);
        $this->assertSame(env('ENVAI_API_KEY'), $production['api_key']);
        $this->assertSame(3, $production['contract_version']);
        $this->assertSame(67_108_864, $production['max_legacy_replay_bytes']);
    }

    public function test_definitions_shape_has_path_and_sync_mode(): void
    {
        $definitions = $this->config()['definitions'];

        $this->assertSame(['path', 'sync_mode'], array_keys($definitions));
        $this->assertSame(base_path('envai'), $definitions['path']);
        $this->assertSame(env('ENVAI_SYNC_MODE', 'deploy'), $definitions['sync_mode']);
    }

    public function test_sync_mode_defaults_to_deploy(): void
    {
        $this->assertSame('deploy', $this->config()['definitions']['sync_mode']);
    }

    public function test_state_shape_matches_section_25(): void
    {
        $state = $this->config()['state'];

        $this->assertSame(['driver', 'file', 'connection', 'table_prefix'], array_keys($state));
        $this->assertSame(env('ENVAI_STATE_DRIVER', 'file'), $state['driver']);
        $this->assertSame(base_path('.envai'), $state['file']);
        $this->assertSame(env('ENVAI_STATE_DB_CONNECTION'), $state['connection']);
        $this->assertSame('envai_sync_', $state['table_prefix']);
    }

    public function test_state_driver_defaults_to_file(): void
    {
        $this->assertSame('file', $this->config()['state']['driver']);
    }

    public function test_webhooks_shape_has_safe_defaults(): void
    {
        $webhooks = $this->config()['webhooks'];

        $this->assertSame([
            'enabled',
            'path',
            'verifier',
            'response_policy',
            'connection',
            'database_connection',
            'table',
            'max_body_bytes',
            'rate_limit_per_minute',
        ], array_keys($webhooks));
        $this->assertFalse($webhooks['enabled']);
        $this->assertSame('api/envai/webhooks', $webhooks['path']);
        $this->assertNull($webhooks['verifier']);
        $this->assertNull($webhooks['response_policy']);
        $this->assertNull($webhooks['connection']);
        $this->assertNull($webhooks['database_connection']);
        $this->assertSame('envai_webhook_receipts', $webhooks['table']);
        $this->assertSame(1048576, $webhooks['max_body_bytes']);
        $this->assertSame(30, $webhooks['rate_limit_per_minute']);
    }

    public function test_env_driven_values_are_read(): void
    {
        $previous = [
            'mode' => getenv('ENVAI_SYNC_MODE'),
            'driver' => getenv('ENVAI_STATE_DRIVER'),
            'base' => getenv('ENVAI_BASE_URL'),
        ];

        putenv('ENVAI_SYNC_MODE=lazy');
        putenv('ENVAI_STATE_DRIVER=laravel');
        putenv('ENVAI_BASE_URL=https://env.example.test');
        $_ENV['ENVAI_SYNC_MODE'] = 'lazy';
        $_ENV['ENVAI_STATE_DRIVER'] = 'laravel';
        $_ENV['ENVAI_BASE_URL'] = 'https://env.example.test';

        try {
            $config = $this->config();

            $this->assertSame('lazy', $config['definitions']['sync_mode']);
            $this->assertSame('laravel', $config['state']['driver']);
            $this->assertSame('https://env.example.test', $config['connections']['production']['base_url']);
        } finally {
            putenv($previous['mode'] === false ? 'ENVAI_SYNC_MODE' : 'ENVAI_SYNC_MODE='.$previous['mode']);
            putenv($previous['driver'] === false ? 'ENVAI_STATE_DRIVER' : 'ENVAI_STATE_DRIVER='.$previous['driver']);
            putenv($previous['base'] === false ? 'ENVAI_BASE_URL' : 'ENVAI_BASE_URL='.$previous['base']);
            unset($_ENV['ENVAI_SYNC_MODE'], $_ENV['ENVAI_STATE_DRIVER'], $_ENV['ENVAI_BASE_URL']);
        }
    }
}
