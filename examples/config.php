<?php

declare(strict_types=1);

/*
 * Illustrative example: framework-independent client configuration.
 *
 * Include this file from the other examples to reuse the client:
 *
 *     $envai = require __DIR__ . '/config.php';
 *
 * Set ENVAI_API_KEY before running. The base URL is the API origin
 * without the canonical /api/v1 prefix; the client appends it.
 */

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\RetryPolicy;
use Digitalygo\Envai\Configuration\SensitiveString;

$config = new EnvaiConfig(
    baseUrl: 'https://api.envai.example',
    apiKey: new SensitiveString(getenv('ENVAI_API_KEY') ?: ''),
    connectionName: 'production',
    connectTimeoutSeconds: 10,
    requestTimeoutSeconds: 60,
    streamTimeoutSeconds: 120,
    retryPolicy: new RetryPolicy(maxAttempts: 3),
);

$envai = new EnvaiClient($config);

return $envai;
