<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Exceptions;

use Digitalygo\Envai\Models\Common\ApiError;

/**
 * File related failures: file error envelopes returned by the API and local
 * file I/O errors. Local errors carry httpStatus 0 and no ApiError.
 */
final class FileException extends ApiException
{
    /**
     * Builds a FileException for a local file I/O failure (no HTTP response).
     *
     * @param  array<string, mixed>  $context
     */
    public static function local(string $message, array $context = [], ?\Throwable $previous = null): self
    {
        return new self($message, 0, null, context: $context, previous: $previous);
    }
}
