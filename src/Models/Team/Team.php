<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Team;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * A team with id `team_<ulid>` owned by a user with id `usr_<ulid>`. It has
 * exactly one orchestrator, a primary-mode agent owned by the same user, and
 * an ordered member list. Timestamps stay ISO 8601 strings (wire-faithful).
 */
final readonly class Team implements ArrayHydratable
{
    /**
     * @param  list<TeamMember>  $members
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $id,
        public string $userId,
        public string $name,
        public ?string $description,
        public string $orchestratorId,
        public array $members,
        public string $createdAt,
        public string $updatedAt,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'Team';
        $rawMembers = $data['members'] ?? null;

        if (! is_array($rawMembers)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "members" must be an array, got %s.',
                $context,
                get_debug_type($rawMembers),
            ));
        }

        $members = [];

        foreach ($rawMembers as $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s payload field "members" must be a list of member objects, got a non-array entry.',
                    $context,
                ));
            }

            $members[] = TeamMember::fromArray(Wire::stringKeyed($item, 'members', $context));
        }

        return new self(
            id: Wire::string($data, 'id', $context),
            userId: Wire::string($data, 'user_id', $context),
            name: Wire::string($data, 'name', $context),
            description: Wire::nullableString($data, 'description', $context),
            orchestratorId: Wire::string($data, 'orchestrator_id', $context),
            members: $members,
            createdAt: Wire::string($data, 'created_at', $context),
            updatedAt: Wire::string($data, 'updated_at', $context),
            extra: Wire::extra($data, ['id', 'user_id', 'name', 'description', 'orchestrator_id', 'members', 'created_at', 'updated_at']),
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
            'description' => $this->description,
            'orchestrator_id' => $this->orchestratorId,
            'members' => array_map(static fn (TeamMember $member): array => $member->toArray(), $this->members),
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ], $this->extra);
    }
}
