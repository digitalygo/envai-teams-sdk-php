<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Uploaded input image record retained for 24 hours. `expires_at` and
 * `created_at` stay ISO 8601 strings (wire-faithful).
 */
final readonly class ImageUploadRecord implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $id,
        public string $mimeType,
        public int $size,
        public string $originalFilename,
        public string $expiresAt,
        public string $createdAt,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'ImageUploadRecord';

        return new self(
            id: Wire::string($data, 'id', $context),
            mimeType: Wire::string($data, 'mime_type', $context),
            size: Wire::int($data, 'size', $context),
            originalFilename: Wire::string($data, 'original_filename', $context),
            expiresAt: Wire::string($data, 'expires_at', $context),
            createdAt: Wire::string($data, 'created_at', $context),
            extra: Wire::extra($data, ['id', 'mime_type', 'size', 'original_filename', 'expires_at', 'created_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'id' => $this->id,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
            'original_filename' => $this->originalFilename,
            'expires_at' => $this->expiresAt,
            'created_at' => $this->createdAt,
        ], $this->extra);
    }
}
