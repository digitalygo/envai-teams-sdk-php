<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

enum TaskStatus: string
{
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
