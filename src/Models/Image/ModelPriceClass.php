<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * Informational price class of a model. `average_price` is the provider
 * weighted average across the providers that expose the class (not a
 * guaranteed quote) and is preserved as a wire-faithful JsonObject with the
 * nullable `amount` and `currency` fields.
 */
final readonly class ModelPriceClass implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $key,
        public string $label,
        public int $providersCount,
        public JsonObject $averagePrice,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'ModelPriceClass';

        return new self(
            key: Wire::string($data, 'key', $context),
            label: Wire::string($data, 'label', $context),
            providersCount: Wire::int($data, 'providers_count', $context),
            averagePrice: Wire::jsonObject($data, 'average_price', $context),
            extra: Wire::extra($data, ['key', 'label', 'providers_count', 'average_price']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'key' => $this->key,
            'label' => $this->label,
            'providers_count' => $this->providersCount,
            'average_price' => $this->averagePrice->toArray(),
        ], $this->extra);
    }
}
