<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

enum WebhookClaimStatus: string
{
    case Claimed = 'claimed';
    case Duplicate = 'duplicate';
    case Collision = 'collision';
    case AlreadyClaimed = 'already_claimed';
}
