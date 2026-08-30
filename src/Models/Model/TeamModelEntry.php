<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Model;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Team alias entry of the catalog: `team_id` is the team key and the entry
 * routes to the team's runtime. `created` is the Unix team creation
 * timestamp and is absent on older records, hence nullable.
 */
final readonly class TeamModelEntry implements ArrayHydratable, ModelListEntry
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $id,
        public string $object,
        public string $type,
        public string $ownedBy,
        public string $teamId,
        public bool $deprecated,
        public ?string $sunsetAt,
        public ?int $created = null,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'TeamModelEntry';

        return new self(
            id: Wire::string($data, 'id', $context),
            object: Wire::string($data, 'object', $context),
            type: Wire::string($data, 'type', $context),
            ownedBy: Wire::string($data, 'owned_by', $context),
            teamId: Wire::string($data, 'team_id', $context),
            deprecated: Wire::boolDefault($data, 'deprecated', $context),
            sunsetAt: Wire::nullableString($data, 'sunset_at', $context),
            created: Wire::nullableInt($data, 'created', $context),
            extra: Wire::extra($data, ['id', 'object', 'type', 'owned_by', 'team_id', 'deprecated', 'sunset_at', 'created']),
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
            'type' => $this->type,
            'owned_by' => $this->ownedBy,
            'team_id' => $this->teamId,
            'deprecated' => $this->deprecated,
            'sunset_at' => $this->sunsetAt,
        ];

        if ($this->created !== null) {
            $payload['created'] = $this->created;
        }

        return array_merge($payload, $this->extra);
    }
}
