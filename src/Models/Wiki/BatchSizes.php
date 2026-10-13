<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Wiki;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class BatchSizes implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public int $inbox,
        public int $outbox,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            inbox: Wire::int($data, 'inbox', self::class),
            outbox: Wire::int($data, 'outbox', self::class),
            extra: Wire::extra($data, ['inbox', 'outbox']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'inbox' => $this->inbox,
            'outbox' => $this->outbox,
        ]);
    }
}
