<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Wiki;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class CategoryIndex implements ArrayHydratable
{
    /**
     * @param  list<CatalogEntry>  $entries
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public array $entries,
        public int $totalCount,
        public int $returnedCount,
        public ?IndexRange $requestedRange,
        public ?IndexRange $actualRange,
        public int $catalogRevision,
        public bool $isComplete,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            entries: self::entriesFromArray($data),
            totalCount: Wire::int($data, 'total_count', self::class),
            returnedCount: Wire::int($data, 'returned_count', self::class),
            requestedRange: ($data['requested_range'] ?? null) === null ? null : IndexRange::fromArray(Wire::objectArray($data, 'requested_range', self::class)),
            actualRange: ($data['actual_range'] ?? null) === null ? null : IndexRange::fromArray(Wire::objectArray($data, 'actual_range', self::class)),
            catalogRevision: Wire::int($data, 'catalog_revision', self::class),
            isComplete: Wire::bool($data, 'is_complete', self::class),
            extra: Wire::extra($data, ['entries', 'total_count', 'returned_count', 'requested_range', 'actual_range', 'catalog_revision', 'is_complete']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'entries' => array_map(static fn (CatalogEntry $item): array => $item->toArray(), $this->entries),
            'total_count' => $this->totalCount,
            'returned_count' => $this->returnedCount,
            'requested_range' => $this->requestedRange?->toArray(),
            'actual_range' => $this->actualRange?->toArray(),
            'catalog_revision' => $this->catalogRevision,
            'is_complete' => $this->isComplete,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<CatalogEntry>
     */
    private static function entriesFromArray(array $data): array
    {
        $values = Wire::presentOrFail($data, 'entries', self::class);
        if (! is_array($values) || ! array_is_list($values)) {
            throw new \InvalidArgumentException('Invalid entries.');
        }
        $result = [];
        foreach ($values as $key => $value) {
            if (! is_array($value)) {
                throw new \InvalidArgumentException('Invalid entries item.');
            }
            $result[] = CatalogEntry::fromArray(Wire::stringKeyed($value, 'item', self::class));
        }

        return $result;
    }
}
