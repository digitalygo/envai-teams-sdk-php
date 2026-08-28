<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Completion;

/**
 * Lifecycle status of a completion (metadata-only turn record).
 */
enum CompletionStatus: string
{
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Canceled = 'canceled';
}
