<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Wiki;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class Category implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $categoryId,
        public string $name,
        public string $description,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            categoryId: Wire::string($data, 'category_id', self::class),
            name: Wire::string($data, 'name', self::class),
            description: Wire::string($data, 'description', self::class),
            extra: Wire::extra($data, ['category_id', 'name', 'description']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'category_id' => $this->categoryId,
            'name' => $this->name,
            'description' => $this->description,
        ]);
    }
}
