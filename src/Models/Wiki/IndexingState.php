<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Wiki;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class IndexingState implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $status,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            status: Wire::string($data, 'status', self::class),
            extra: Wire::extra($data, ['status']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'status' => $this->status,
        ]);
    }
}
