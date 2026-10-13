<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Wiki;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class Categories implements ArrayHydratable
{
    /**
     * @param  list<Category>  $categories
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public array $categories,
        public int $catalogRevision,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            categories: self::categoriesFromArray($data),
            catalogRevision: Wire::int($data, 'catalog_revision', self::class),
            extra: Wire::extra($data, ['categories', 'catalog_revision']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'categories' => array_map(static fn (Category $item): array => $item->toArray(), $this->categories),
            'catalog_revision' => $this->catalogRevision,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<Category>
     */
    private static function categoriesFromArray(array $data): array
    {
        $values = Wire::presentOrFail($data, 'categories', self::class);
        if (! is_array($values) || ! array_is_list($values)) {
            throw new \InvalidArgumentException('Invalid categories.');
        }
        $result = [];
        foreach ($values as $key => $value) {
            if (! is_array($value)) {
                throw new \InvalidArgumentException('Invalid categories item.');
            }
            $result[] = Category::fromArray(Wire::stringKeyed($value, 'item', self::class));
        }

        return $result;
    }
}
