<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Team;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Team member record with the resolved agent name and the pivot position and
 * role. `position` is derived from the request array order and `role` is the
 * team scoped role label, null when not set.
 */
final readonly class TeamMember implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $agentDefinitionId,
        public string $name,
        public int $position,
        public ?string $role,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'TeamMember';

        return new self(
            agentDefinitionId: Wire::string($data, 'agent_definition_id', $context),
            name: Wire::string($data, 'name', $context),
            position: Wire::int($data, 'position', $context),
            role: Wire::nullableString($data, 'role', $context),
            extra: Wire::extra($data, ['agent_definition_id', 'name', 'position', 'role']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'agent_definition_id' => $this->agentDefinitionId,
            'name' => $this->name,
            'position' => $this->position,
        ];

        if ($this->role !== null) {
            $payload['role'] = $this->role;
        }

        return array_merge($payload, $this->extra);
    }
}
