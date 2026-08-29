<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks\Contracts;

use Digitalygo\Envai\Webhooks\WebhookClaimResult;
use Digitalygo\Envai\Webhooks\WebhookIdempotencyKey;

interface WebhookIdempotencyStore
{
    public function claim(WebhookIdempotencyKey $key): WebhookClaimResult;

    public function markDispatched(WebhookIdempotencyKey $key): void;

    public function markFailed(WebhookIdempotencyKey $key): void;
}
