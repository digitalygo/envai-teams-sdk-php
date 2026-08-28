<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http;

/**
 * A plain text part of a multipart body.
 */
final readonly class MultipartField
{
    public function __construct(
        public string $name,
        public string $value,
    ) {}
}
