<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Http\Client;

use Digitalygo\Envai\Models\Common\ArrayHydratable;

/**
 * Minimal ArrayHydratable DTO used by the codec and executor tests. Unknown
 * fields are preserved in $extra, mirroring the models package contract.
 */
final readonly class TestResource implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra
     */
    private function __construct(
        public string $id,
        public ?string $name = null,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return new self(
            is_string($data['id'] ?? null) ? $data['id'] : '',
            isset($data['name']) && is_string($data['name']) ? $data['name'] : null,
            array_diff_key($data, array_flip(['id', 'name'])),
        );
    }
}
