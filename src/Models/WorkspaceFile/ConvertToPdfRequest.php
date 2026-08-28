<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\WorkspaceFile;

/**
 * Payload of POST /files/convert-to-pdf. The `path` must reference a
 * markdown or text file (`.md`, `.markdown` or `.txt`), otherwise the
 * request is rejected with 422 by the server.
 */
final readonly class ConvertToPdfRequest
{
    public function __construct(
        public string $path,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['path' => $this->path];
    }
}
