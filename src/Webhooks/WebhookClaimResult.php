<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

final readonly class WebhookClaimResult
{
    public function __construct(
        public WebhookClaimStatus $status,
        public ?WebhookIdempotencyRecord $record = null,
    ) {}
}
