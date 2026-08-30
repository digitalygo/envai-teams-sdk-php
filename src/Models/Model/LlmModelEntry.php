<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Model;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * LLM entry of the catalog. `owned_by` is the model provider, `created` is
 * the Unix release timestamp, models past sunset are removed from the
 * catalog and deprecated models appear with `deprecated: true` until sunset.
 *
 * `sunset_at` stays an ISO 8601 string (wire-faithful), never parsed into
 * DateTimeImmutable.
 */
final readonly class LlmModelEntry implements ArrayHydratable, ModelListEntry
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $id,
        public string $object,
        public int $created,
        public string $ownedBy,
        public string $type,
        public bool $deprecated,
        public ?string $sunsetAt,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'LlmModelEntry';

        return new self(
            id: Wire::string($data, 'id', $context),
            object: Wire::string($data, 'object', $context),
            created: Wire::int($data, 'created', $context),
            ownedBy: Wire::string($data, 'owned_by', $context),
            type: Wire::string($data, 'type', $context),
            deprecated: Wire::bool($data, 'deprecated', $context),
            sunsetAt: Wire::nullableString($data, 'sunset_at', $context),
            extra: Wire::extra($data, ['id', 'object', 'created', 'owned_by', 'type', 'deprecated', 'sunset_at']),
        );
    }

    public function id(): string
    {
        return $this->id;
    }

    public function object(): string
    {
        return $this->object;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function ownedBy(): string
    {
        return $this->ownedBy;
    }

    public function deprecated(): bool
    {
        return $this->deprecated;
    }

    public function sunsetAt(): ?string
    {
        return $this->sunsetAt;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'id' => $this->id,
            'object' => $this->object,
            'created' => $this->created,
            'owned_by' => $this->ownedBy,
            'type' => $this->type,
            'deprecated' => $this->deprecated,
            'sunset_at' => $this->sunsetAt,
        ];

        return array_merge($payload, $this->extra);
    }
}
