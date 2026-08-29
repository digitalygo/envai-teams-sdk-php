<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

final readonly class WebhookProcessingResult
{
    public function __construct(
        public WebhookOutcome $outcome,
        public ?string $payloadHash = null,
        public ?WebhookReceived $event = null,
    ) {}

    public function wasDispatched(): bool
    {
        return $this->outcome === WebhookOutcome::Dispatched;
    }

    public function isDuplicate(): bool
    {
        return $this->outcome === WebhookOutcome::Duplicate;
    }

    public function isRejected(): bool
    {
        return ! in_array($this->outcome, [WebhookOutcome::Dispatched, WebhookOutcome::Duplicate], true);
    }
}
