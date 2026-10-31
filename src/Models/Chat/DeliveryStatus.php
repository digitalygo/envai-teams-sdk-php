<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

enum DeliveryStatus: string
{
    case Queued = 'queued';
    case Delivered = 'delivered';
    case Cancelled = 'cancelled';
    case Failed = 'failed';
}
