<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks\Contracts;

use Digitalygo\Envai\Webhooks\WebhookReceived;

interface WebhookDispatcher
{
    public function dispatch(WebhookReceived $event): void;
}
