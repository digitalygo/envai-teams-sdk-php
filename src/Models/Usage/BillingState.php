<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Usage;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class BillingState implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $status,
        public array $extra = [],
    ) {
        if ($status !== 'inactive') {
            throw new \InvalidArgumentException('Wiki V1 billing must be inactive.');
        }
    }

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
