<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\ChatCron;

/** Delivery target mode of a chat cron. */
enum ChatCronTargetMode: string
{
    case SameChat = 'same_chat';
    case NewChat = 'new_chat';
}
