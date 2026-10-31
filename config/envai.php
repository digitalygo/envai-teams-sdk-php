<?php

declare(strict_types=1);

/*
 * Envai configuration for the Laravel integration. Publish with:
 *
 *     php artisan vendor:publish --tag=envai-config
 *
 * The base URL is the API origin without the canonical /api/v1 prefix;
 * the client appends it.
 */

return [
    'default' => 'production',

    'connections' => [
        'production' => [
            'base_url' => env('ENVAI_BASE_URL'),
            'api_key' => env('ENVAI_API_KEY'),
            'contract_version' => 3,
            'native_tasks' => false,
            'strict_validation' => false,
            'jwks_url' => null,
            'headers' => [],
            'connect_timeout_seconds' => 10,
            'request_timeout_seconds' => 60,
            'stream_timeout_seconds' => null,
            'retry_policy' => null,
            'max_legacy_replay_bytes' => 67_108_864,
        ],
    ],

    'definitions' => [
        'path' => base_path('envai'),
        'sync_mode' => env('ENVAI_SYNC_MODE', 'deploy'),
    ],

    'state' => [
        'driver' => env('ENVAI_STATE_DRIVER', 'file'),
        'file' => base_path('.envai'),
        'connection' => env('ENVAI_STATE_DB_CONNECTION'),
        'table_prefix' => 'envai_sync_',
    ],

    'webhooks' => [
        'enabled' => env('ENVAI_WEBHOOKS_ENABLED', false),
        'path' => env('ENVAI_WEBHOOKS_PATH', 'api/envai/webhooks'),
        'verifier' => null,
        'response_policy' => null,
        'connection' => null,
        'database_connection' => null,
        'table' => 'envai_webhook_receipts',
        'max_body_bytes' => 1048576,
        'rate_limit_per_minute' => 30,
    ],
];
