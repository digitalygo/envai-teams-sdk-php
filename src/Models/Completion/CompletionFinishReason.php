<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Completion;

/**
 * Optional finish reason of a completion, null while the turn is running.
 */
enum CompletionFinishReason: string
{
    case Stop = 'stop';
    case Error = 'error';
    case Canceled = 'canceled';
}
