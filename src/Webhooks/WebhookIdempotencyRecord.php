<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

final readonly class WebhookIdempotencyRecord
{
    public function __construct(
        public WebhookIdempotencyKey $key,
        public WebhookRecordStatus $status,
        public \DateTimeImmutable $updatedAt,
    ) {}
}
