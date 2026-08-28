<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Client;

use Digitalygo\Envai\Models\Common\ArrayHydratable;

/**
 * Top-level acknowledgment DTO (chat execution acks), decoded without any
 * {data} wrapper.
 */
final readonly class TestAck implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function __construct(
        public string $status,
        public ?string $turnId = null,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return new self(
            is_string($data['status'] ?? null) ? $data['status'] : '',
            isset($data['turn_id']) && is_string($data['turn_id']) ? $data['turn_id'] : null,
            array_diff_key($data, array_flip(['status', 'turn_id'])),
        );
    }
}
