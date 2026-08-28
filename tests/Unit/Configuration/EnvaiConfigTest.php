<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Unit\Configuration;

use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\RetryPolicy;
use Digitalygo\Envai\Configuration\SensitiveString;
use PHPUnit\Framework\TestCase;

final class EnvaiConfigTest extends TestCase
{
    public function test_rejects_non_http_scheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EnvaiConfig('ftp://api.envai.example', new SensitiveString('sk'));
    }

    public function test_rejects_empty_base_url(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EnvaiConfig('', new SensitiveString('sk'));
    }

    public function test_rejects_base_url_without_scheme(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EnvaiConfig('api.envai.example', new SensitiveString('sk'));
    }

    public function test_rejects_non_positive_connect_timeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EnvaiConfig('https://api.envai.example', new SensitiveString('sk'), connectTimeoutSeconds: 0);
    }

    public function test_rejects_non_positive_request_timeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EnvaiConfig('https://api.envai.example', new SensitiveString('sk'), requestTimeoutSeconds: -5);
    }

    public function test_rejects_non_positive_stream_timeout(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EnvaiConfig('https://api.envai.example', new SensitiveString('sk'), streamTimeoutSeconds: 0);
    }

    public function test_rejects_empty_api_key(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new EnvaiConfig('https://api.envai.example', new SensitiveString(''));
    }

    public function test_valid_construction(): void
    {
        $config = new EnvaiConfig('https://api.envai.example', new SensitiveString('sk-secret'));

        $this->assertSame('https://api.envai.example', $config->baseUrl);
        $this->assertSame('sk-secret', $config->apiKey->reveal());
        $this->assertSame('default', $config->connectionName);
        $this->assertSame(10, $config->connectTimeoutSeconds);
        $this->assertSame(60, $config->requestTimeoutSeconds);
        $this->assertNull($config->streamTimeoutSeconds);
        $this->assertSame([], $config->headers);
        $this->assertNull($config->retryPolicy);
    }

    public function test_custom_retry_policy_is_accepted(): void
    {
        $policy = new RetryPolicy(maxAttempts: 5, maxDelayMs: 30_000);

        $config = new EnvaiConfig('https://api.envai.example', new SensitiveString('sk'), retryPolicy: $policy);

        $this->assertSame($policy, $config->retryPolicy);
    }

    public function test_jwks_is_derived_from_base_url_origin(): void
    {
        $config = new EnvaiConfig('https://api.envai.example:8443', new SensitiveString('sk'));

        $this->assertSame('https://api.envai.example:8443/.well-known/jwks.json', $config->effectiveJwksUrl());
    }

    public function test_explicit_jwks_url_wins_over_derivation(): void
    {
        $config = new EnvaiConfig('https://api.envai.example', new SensitiveString('sk'), jwksUrl: 'https://keys.envai.example/jwks.json');

        $this->assertSame('https://keys.envai.example/jwks.json', $config->effectiveJwksUrl());
    }
}
