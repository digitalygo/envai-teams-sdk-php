<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

/**
 * Multipart payload for POST /tests/images/generate: up to 4 image files
 * together with the prompt and the model code.
 *
 * `files` carries the local file paths the HTTP client reads as multipart
 * parts; each file must be a valid image of at most 10240 kilobytes (server
 * enforced).
 */
final readonly class TestImageGenerateRequest
{
    /**
     * @param  list<string>  $files  Local paths of the image files to upload.
     */
    public function __construct(
        public array $files,
        public string $prompt,
        public string $modelCode,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'files' => $this->files,
            'prompt' => $this->prompt,
            'model_code' => $this->modelCode,
        ];
    }
}
