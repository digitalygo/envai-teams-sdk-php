<?php

declare(strict_types=1);

use Digitalygo\Envai\Integration\Laravel\Actions\ReceiveEnvaiWebhook;
use Digitalygo\Envai\Integration\Laravel\Http\Middleware\RejectOversizedWebhookBody;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

$path = Config::get('envai.webhooks.path');
if (! is_string($path) || $path === '') {
    throw new LogicException('The Envai webhook route path must be a non-empty string.');
}

return Route::post(
    $path,
    ReceiveEnvaiWebhook::class,
)->middleware([
    'api',
    RejectOversizedWebhookBody::class,
    'throttle:envai-webhooks',
]);
