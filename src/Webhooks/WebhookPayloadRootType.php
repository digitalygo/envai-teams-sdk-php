<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Webhooks;

enum WebhookPayloadRootType: string
{
    case Object = 'object';
    case List = 'list';
}
