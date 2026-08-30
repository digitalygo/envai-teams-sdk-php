<?php

declare(strict_types=1);

use Digitalygo\Envai\Integration\Laravel\Actions\ReceiveEnvaiWebhook;
use Digitalygo\Envai\Integration\Laravel\Http\Middleware\RejectOversizedWebhookBody;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;

return Route::post(
    (string) Config::get('envai.webhooks.path'),
    ReceiveEnvaiWebhook::class,
)->middleware([
    'api',
    RejectOversizedWebhookBody::class,
    'throttle:envai-webhooks',
]);
