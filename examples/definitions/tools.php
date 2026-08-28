<?php

declare(strict_types=1);

/*
 * Illustrative example: the client tool registry.
 *
 * The array key is the remote Envai tool name, not the class basename.
 * Entries accept a handler class (default timeout 120 seconds) or an
 * array with a class and a timeout clamped to the 1..600 range. The
 * handler classes live in examples/client-tool.php.
 */

use Examples\Tools\QueryForecastingDatabase;
use Examples\Tools\RetrieveMarketPrices;

return [
    'query_forecasting_database' => QueryForecastingDatabase::class,
    'retrieve_market_prices' => [
        'class' => RetrieveMarketPrices::class,
        'timeout_seconds' => 60,
    ],
];
