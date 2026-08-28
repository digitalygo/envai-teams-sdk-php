<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http;

/**
 * Whether automatic retries are allowed for this request.
 *
 * Only operations classified as retry-safe may be retried automatically.
 * Ambiguous mutations (create, send, fork, restore, tool resolution, apply)
 * must be marked NeverRetry.
 */
enum RetrySafety
{
    case Safe;

    case NeverRetry;
}
