<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * Provider specific webhook body, passed through as a wire-faithful
 * JsonObject.
 *
 * The shape depends on the provider and unknown fields are tolerated by the
 * provider parsers, so this DTO is deliberately a passthrough: it carries no
 * $extra property because the whole payload is by definition provider owned.
 */
final readonly class ProviderWebhookPayload implements ArrayHydratable
{
    public function __construct(
        public JsonObject $payload,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return new self(new JsonObject($data));
    }

    /**
     * @return array<array-key, mixed>
     */
    public function toArray(): array
    {
        return $this->payload->toArray();
    }
}
