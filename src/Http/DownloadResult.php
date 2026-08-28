<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Http;

/**
 * Metadata describing a streamed binary download.
 */
final readonly class DownloadResult
{
    /**
     * @param  array<string, list<string>>  $headers  Full response headers.
     */
    public function __construct(
        public string $mediaType,
        public ?string $filename = null,
        public ?int $contentLength = null,
        public array $headers = [],
    ) {}
}
