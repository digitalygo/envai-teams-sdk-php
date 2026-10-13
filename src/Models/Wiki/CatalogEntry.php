<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Wiki;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

final readonly class CatalogEntry implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $entryId,
        public string $type,
        public string $targetId,
        public string $canonicalPath,
        public string $description,
        public string $insertedAt,
        public array $extra = [],
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): static
    {
        return new self(
            entryId: Wire::string($data, 'entry_id', self::class),
            type: Wire::string($data, 'type', self::class),
            targetId: Wire::string($data, 'target_id', self::class),
            canonicalPath: Wire::string($data, 'canonical_path', self::class),
            description: Wire::string($data, 'description', self::class),
            insertedAt: Wire::string($data, 'inserted_at', self::class),
            extra: Wire::extra($data, ['entry_id', 'type', 'target_id', 'canonical_path', 'description', 'inserted_at']),
        );
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_merge($this->extra, [
            'entry_id' => $this->entryId,
            'type' => $this->type,
            'target_id' => $this->targetId,
            'canonical_path' => $this->canonicalPath,
            'description' => $this->description,
            'inserted_at' => $this->insertedAt,
        ]);
    }
}
