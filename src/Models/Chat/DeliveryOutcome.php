<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

enum DeliveryOutcome: string
{
    case Completed = 'completed';
    case Blocked = 'blocked';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
