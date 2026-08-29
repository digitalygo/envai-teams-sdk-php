<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks\Contracts;

use Digitalygo\Envai\Webhooks\WebhookProcessingResult;

interface WebhookResponsePolicy
{
    public function responseFor(WebhookProcessingResult $result): object;
}
