<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Metadata of a single uploaded test image file.
 */
final readonly class TestImageFileMetadata implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $originalName,
        public string $mimeType,
        public int $size,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'TestImageFileMetadata';

        return new self(
            originalName: Wire::string($data, 'original_name', $context),
            mimeType: Wire::string($data, 'mime_type', $context),
            size: Wire::int($data, 'size', $context),
            extra: Wire::extra($data, ['original_name', 'mime_type', 'size']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'original_name' => $this->originalName,
            'mime_type' => $this->mimeType,
            'size' => $this->size,
        ], $this->extra);
    }
}
