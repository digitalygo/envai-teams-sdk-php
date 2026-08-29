<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks\Contracts;

use Digitalygo\Envai\Webhooks\WebhookPayload;
use Digitalygo\Envai\Webhooks\WebhookRequest;
use Digitalygo\Envai\Webhooks\WebhookVerificationResult;

interface WebhookVerifier
{
    public function verify(WebhookRequest $request, WebhookPayload $payload): WebhookVerificationResult;
}
