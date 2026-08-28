<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\WorkspaceFile;

/**
 * Shared payload of file create and update. `path` is an absolute
 * workspace-relative path, `content` is the file body (non empty, valid
 * UTF-8, at most 1048576 bytes and 1000 lines; writing `/MEMORY.md`
 * additionally caps the content at 12000 characters, all server enforced).
 */
final readonly class WorkspaceFileRequest
{
    public function __construct(
        public string $path,
        public string $content,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'path' => $this->path,
            'content' => $this->content,
        ];
    }
}
