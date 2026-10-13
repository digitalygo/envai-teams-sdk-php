<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Client;

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Definitions\Compiler\DefinitionCompiler;
use Digitalygo\Envai\Tools\Contracts\ReflectionClientToolFactory;
use Digitalygo\Envai\Tools\Execution\ClientToolRegistry;
use PHPUnit\Framework\TestCase;

final class PublicExamplesTest extends TestCase
{
    public function test_example_definitions_compile_without_missing_agents_or_handlers(): void
    {
        require_once dirname(__DIR__, 2).'/examples/client-tool.php';

        $result = (new DefinitionCompiler)->compile(
            dirname(__DIR__, 2).'/examples/definitions',
            new ReflectionClientToolFactory,
        );

        $this->assertTrue($result->valid, json_encode($result->violations, JSON_THROW_ON_ERROR));
        $this->assertCount(3, $result->manifest->agents);
        $this->assertCount(2, $result->manifest->tools);
        $this->assertCount(1, $result->manifest->teams);
    }

    public function test_example_registry_loads_both_documented_handlers(): void
    {
        require_once dirname(__DIR__, 2).'/examples/client-tool.php';

        $registry = ClientToolRegistry::fromArray(require dirname(__DIR__, 2).'/examples/definitions/tools.php');

        $this->assertNotNull($registry->lookup('query_forecasting_database'));
        $this->assertNotNull($registry->lookup('retrieve_market_prices'));
    }

    public function test_example_config_uses_the_explicit_environment_without_network_access(): void
    {
        $previousBaseUrl = getenv('ENVAI_BASE_URL');
        $previousApiKey = getenv('ENVAI_API_KEY');
        putenv('ENVAI_BASE_URL=https://envai.example.test');
        putenv('ENVAI_API_KEY=example-test-key');

        try {
            $client = require dirname(__DIR__, 2).'/examples/config.php';

            $this->assertInstanceOf(EnvaiClient::class, $client);
            $this->assertSame('https://envai.example.test', $client->config()->baseUrl);
            $this->assertSame('https://envai.example.test/.well-known/jwks.json', $client->config()->effectiveJwksUrl());
        } finally {
            putenv($previousBaseUrl === false ? 'ENVAI_BASE_URL' : 'ENVAI_BASE_URL='.$previousBaseUrl);
            putenv($previousApiKey === false ? 'ENVAI_API_KEY' : 'ENVAI_API_KEY='.$previousApiKey);
        }
    }
}
