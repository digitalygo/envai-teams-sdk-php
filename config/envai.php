<?php

declare(strict_types=1);

/*
 * Envai configuration for the Laravel integration (project document
 * section 25). Publish with:
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
];
