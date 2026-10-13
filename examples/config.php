<?php

declare(strict_types=1);

/*
 * Illustrative example: framework-independent client configuration.
 *
 * Include this file from the other examples to reuse the client:
 *
 *     $envai = require __DIR__ . '/config.php';
 *
 * Set ENVAI_BASE_URL and ENVAI_API_KEY before running. The base URL is
 * normally the API origin; the client appends /api/v1 exactly once.
 */

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\RetryPolicy;
use Digitalygo\Envai\Configuration\SensitiveString;

require_once dirname(__DIR__).'/vendor/autoload.php';

$baseUrl = getenv('ENVAI_BASE_URL');
$apiKey = getenv('ENVAI_API_KEY');

if ($baseUrl === false || $baseUrl === '' || $apiKey === false || $apiKey === '') {
    throw new RuntimeException('Set ENVAI_BASE_URL and ENVAI_API_KEY.');
}

$config = new EnvaiConfig(
    baseUrl: $baseUrl,
    apiKey: new SensitiveString($apiKey),
    connectionName: 'production',
    connectTimeoutSeconds: 10,
    requestTimeoutSeconds: 60,
    streamTimeoutSeconds: 120,
    retryPolicy: new RetryPolicy(maxAttempts: 3),
);

$envai = new EnvaiClient($config);

return $envai;
