<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Integration\Laravel\WebhookDispatcher;

use Digitalygo\Envai\Integration\Laravel\Events\EnvaiWebhookReceived;
use Digitalygo\Envai\Webhooks\Contracts\WebhookDispatcher;
use Digitalygo\Envai\Webhooks\WebhookReceived;
use Illuminate\Contracts\Events\Dispatcher;

final readonly class LaravelWebhookEventDispatcher implements WebhookDispatcher
{
    public function __construct(
        private Dispatcher $dispatcher,
        private string $connectionName,
    ) {
        if (trim($connectionName) === '' || preg_match('/[\r\n\x00-\x1F\x7F]/', $connectionName) === 1) {
            throw new \InvalidArgumentException('The webhook connection name must be non-empty and safe.');
        }
    }

    public function dispatch(WebhookReceived $event): void
    {
        $this->dispatcher->dispatch(new EnvaiWebhookReceived($event, $this->connectionName));
    }
}
