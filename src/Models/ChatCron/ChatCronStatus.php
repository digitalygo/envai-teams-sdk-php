<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\ChatCron;

/** Scheduling status of a chat cron; cancelled crons are hidden from the API. */
enum ChatCronStatus: string
{
    case Active = 'active';
    case Completed = 'completed';
}
