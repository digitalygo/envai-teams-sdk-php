<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Workspace;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * A workspace owns the wiki, skills and `MEMORY.md` state; it does not own a
 * team (teams are selected per chat).
 *
 * `metadata` is stored server side as JSON derived from a PHP array, so it
 * hydrates as a JsonObject for associative payloads and as a JsonArray for
 * list payloads; an empty payload becomes a JsonArray. Timestamps stay ISO
 * 8601 strings (wire-faithful), never parsed into DateTimeImmutable.
 */
final readonly class Workspace implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $id,
        public string $userId,
        public string $name,
        public JsonObject|JsonArray $metadata,
        public string $createdAt,
        public string $updatedAt,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'Workspace';

        return new self(
            id: Wire::string($data, 'id', $context),
            userId: Wire::string($data, 'user_id', $context),
            name: Wire::string($data, 'name', $context),
            metadata: Wire::jsonObjectOrArray($data, 'metadata', $context),
            createdAt: Wire::string($data, 'created_at', $context),
            updatedAt: Wire::string($data, 'updated_at', $context),
            extra: Wire::extra($data, ['id', 'user_id', 'name', 'metadata', 'created_at', 'updated_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'id' => $this->id,
            'user_id' => $this->userId,
            'name' => $this->name,
            'metadata' => $this->metadata->toArray(),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ], $this->extra);
    }
}
