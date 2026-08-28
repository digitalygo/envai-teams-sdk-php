<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Support;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Exceptions\DefinitionsNotSynchronizedException;
use Digitalygo\Envai\Support\ConsoleBootstrap;
use Digitalygo\Envai\Sync\DefinitionsService;
use PHPUnit\Framework\TestCase;

final class ConsoleBootstrapTest extends TestCase
{
    private const ORIGINAL = [
        'ENVAI_BASE_URL',
        'ENVAI_API_KEY',
        'ENVAI_CONNECTION',
        'ENVAI_DEFINITIONS_PATH',
        'ENVAI_STATE_DRIVER',
        'ENVAI_STATE_PATH',
    ];

    protected function setUp(): void
    {
        foreach (self::ORIGINAL as $name) {
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach (self::ORIGINAL as $name) {
            putenv($name);
        }
    }

    public function test_config_reads_the_environment(): void
    {
        putenv('ENVAI_BASE_URL=https://api.example.test');
        putenv('ENVAI_API_KEY=sk-secret');
        putenv('ENVAI_CONNECTION=prod');
        putenv('ENVAI_DEFINITIONS_PATH=definitions');
        putenv('ENVAI_STATE_DRIVER=file');
        putenv('ENVAI_STATE_PATH=/tmp/state.json');

        $config = ConsoleBootstrap::config();

        $this->assertSame('https://api.example.test', $config->baseUrl);
        $this->assertSame('sk-secret', $config->apiKey->reveal());
        $this->assertSame('prod', $config->connectionName);
        $this->assertSame('definitions', $config->definitionsPath);
        $this->assertSame('file', $config->stateDriver);
        $this->assertSame('/tmp/state.json', $config->stateFilePath);
    }

    public function test_config_applies_the_documented_defaults(): void
    {
        putenv('ENVAI_BASE_URL=https://api.example.test');
        putenv('ENVAI_API_KEY=sk-secret');

        $config = ConsoleBootstrap::config();

        $this->assertSame('default', $config->connectionName);
        $this->assertSame('envai', $config->definitionsPath);
        $this->assertSame('file', $config->stateDriver);
        $this->assertSame('.envai', $config->stateFilePath);
    }

    public function test_config_requires_the_mandatory_variables(): void
    {
        putenv('ENVAI_API_KEY=sk-secret');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ENVAI_BASE_URL');

        ConsoleBootstrap::config();
    }

    public function test_client_builds_an_envai_client(): void
    {
        putenv('ENVAI_BASE_URL=https://api.example.test');
        putenv('ENVAI_API_KEY=sk-secret');

        $client = ConsoleBootstrap::client();

        $this->assertInstanceOf(EnvaiClient::class, $client);
        $this->assertSame('https://api.example.test', $client->config()->baseUrl);
    }

    public function test_definitions_factory_returns_a_service_for_the_resolved_options(): void
    {
        putenv('ENVAI_BASE_URL=https://api.example.test');
        putenv('ENVAI_API_KEY=sk-secret');

        $factory = ConsoleBootstrap::definitionsFactory();

        $this->assertInstanceOf(\Closure::class, $factory);

        $dir = sys_get_temp_dir().'/atp-boot-'.bin2hex(random_bytes(4));
        mkdir($dir.'/agents', 0777, true);
        mkdir($dir.'/teams', 0777, true);
        file_put_contents($dir.'/agents/orchestrator.md', "---\nformat: 1\nname: orchestrator\nmode: primary\n---\nYou orchestrate.\n");
        file_put_contents($dir.'/teams/forecasting.php', "<?php\n\nuse Digitalygo\\Envai\\Definitions\\Team;\n\nreturn Team::make('forecasting')->name('Forecasting team')->orchestrator('orchestrator');\n");

        $service = $factory('staging', $dir, 'inmemory', '/tmp/state.json');

        $this->assertInstanceOf(DefinitionsService::class, $service);

        // The connection of the built service surfaces through the
        // readiness gate message.
        try {
            $service->requireSynchronized();
            $this->fail('requireSynchronized must throw before any sync.');
        } catch (DefinitionsNotSynchronizedException $exception) {
            $this->assertStringContainsString('staging', $exception->getMessage());
        }
    }
}
