<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Image;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Price persisted with the generation record and calculated at finalization.
 * The amount is a decimal string with 6 digits after the point in the given
 * currency.
 */
final readonly class ImagePrice implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $amount,
        public string $currency,
        public string $source,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'ImagePrice';

        return new self(
            amount: Wire::string($data, 'amount', $context),
            currency: Wire::string($data, 'currency', $context),
            source: Wire::string($data, 'source', $context),
            extra: Wire::extra($data, ['amount', 'currency', 'source']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'amount' => $this->amount,
            'currency' => $this->currency,
            'source' => $this->source,
        ], $this->extra);
    }
}
