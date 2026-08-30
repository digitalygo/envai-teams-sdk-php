<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Pagination;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Pagination metadata following the Laravel paginator shape: `from` and `to`
 * are the first and last item positions on the page and are null on an
 * empty page.
 */
final readonly class PageMeta implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public int $currentPage,
        public ?int $from,
        public int $lastPage,
        public int $perPage,
        public ?int $to,
        public int $total,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'PageMeta';

        return new self(
            currentPage: Wire::int($data, 'current_page', $context),
            from: Wire::nullableInt($data, 'from', $context),
            lastPage: Wire::int($data, 'last_page', $context),
            perPage: Wire::int($data, 'per_page', $context),
            to: Wire::nullableInt($data, 'to', $context),
            total: Wire::int($data, 'total', $context),
            extra: Wire::extra($data, ['current_page', 'from', 'last_page', 'per_page', 'to', 'total']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'current_page' => $this->currentPage,
            'from' => $this->from,
            'last_page' => $this->lastPage,
            'per_page' => $this->perPage,
            'to' => $this->to,
            'total' => $this->total,
        ], $this->extra);
    }
}
