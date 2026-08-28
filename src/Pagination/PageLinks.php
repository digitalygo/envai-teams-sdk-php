<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Pagination;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Hypermedia links of a paginated response, following the Laravel
 * paginator shape. Every link is nullable: a page without a next (or
 * previous) page reports null for that link.
 */
final readonly class PageLinks implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public ?string $first,
        public ?string $last,
        public ?string $prev,
        public ?string $next,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'PageLinks';

        return new self(
            first: Wire::nullableString($data, 'first', $context),
            last: Wire::nullableString($data, 'last', $context),
            prev: Wire::nullableString($data, 'prev', $context),
            next: Wire::nullableString($data, 'next', $context),
            extra: Wire::extra($data, ['first', 'last', 'prev', 'next']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'first' => $this->first,
            'last' => $this->last,
            'prev' => $this->prev,
            'next' => $this->next,
        ], $this->extra);
    }
}
