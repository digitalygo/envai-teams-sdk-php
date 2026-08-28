<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

/**
 * Explicit output dimensions of an image generation.
 */
final readonly class ImageDimensions
{
    public function __construct(
        public int $width,
        public int $height,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'width' => $this->width,
            'height' => $this->height,
        ];
    }
}
