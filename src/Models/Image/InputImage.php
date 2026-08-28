<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Input image reference used by `input_images`, `input_image`, `mask_image`
 * and `objects_image`.
 *
 * Shared value type of requests (and of the test echo endpoint): it exposes
 * an enum-backed `type`, a `payload` interpreted accordingly, an optional
 * client `reference` and an optional declared `mime_type`. Unknown fields
 * are rejected by the server and are never serialized here.
 */
final readonly class InputImage
{
    public function __construct(
        public InputImageType $type,
        public string $payload,
        public ?string $reference = null,
        public ?string $mimeType = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'InputImage';

        return new self(
            type: Wire::enum($data, 'type', InputImageType::class, $context),
            payload: Wire::string($data, 'payload', $context),
            reference: Wire::nullableString($data, 'reference', $context),
            mimeType: Wire::nullableString($data, 'mime_type', $context),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'type' => $this->type->value,
            'payload' => $this->payload,
        ];

        if ($this->reference !== null) {
            $payload['reference'] = $this->reference;
        }

        if ($this->mimeType !== null) {
            $payload['mime_type'] = $this->mimeType;
        }

        return $payload;
    }
}
