<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

/**
 * Current persisted lifecycle status of an image generation. The 202
 * response reflects the lifecycle state persisted at creation time and is
 * not guaranteed to be `accepted`: it may already reflect a later state when
 * the provider answers synchronously.
 */
enum ImageGenerationLifecycleStatus: string
{
    case Accepted = 'accepted';
    case Submitted = 'submitted';
    case Queued = 'queued';
    case Processing = 'processing';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
