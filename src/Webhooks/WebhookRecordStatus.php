<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

enum WebhookRecordStatus: string
{
    case Claimed = 'claimed';
    case Dispatched = 'dispatched';
    case Failed = 'failed';
}
