<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Team;

use Digitalygo\Envai\Models\Common\OptionalField;

/**
 * Payload to update a team. Any subset of `name`, `description`,
 * `orchestrator_id` and `members` is accepted and at least one updatable
 * field is required. When `members` is present the member list is replaced
 * wholesale and an empty array clears all members.
 *
 * `description` distinguishes an omitted field from an explicit null (which
 * clears the description), so fields are wrapped in OptionalField and
 * toArray() emits only present values. Use the static factories.
 */
final readonly class TeamUpdateRequest
{
    public function __construct(
        public OptionalField $name,
        public OptionalField $description,
        public OptionalField $orchestratorId,
        public OptionalField $members,
    ) {}

    public static function name(string $name): self
    {
        return new self(
            name: OptionalField::of($name),
            description: OptionalField::absent(),
            orchestratorId: OptionalField::absent(),
            members: OptionalField::absent(),
        );
    }

    public static function description(?string $description): self
    {
        return new self(
            name: OptionalField::absent(),
            description: OptionalField::of($description),
            orchestratorId: OptionalField::absent(),
            members: OptionalField::absent(),
        );
    }

    public static function orchestratorId(string $orchestratorId): self
    {
        return new self(
            name: OptionalField::absent(),
            description: OptionalField::absent(),
            orchestratorId: OptionalField::of($orchestratorId),
            members: OptionalField::absent(),
        );
    }

    /**
     * @param  list<TeamMemberCreateRequest>  $members
     */
    public static function members(array $members): self
    {
        return new self(
            name: OptionalField::absent(),
            description: OptionalField::absent(),
            orchestratorId: OptionalField::absent(),
            members: OptionalField::of($members),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];

        if ($this->name->isPresent()) {
            $payload['name'] = $this->name->value();
        }

        if ($this->description->isPresent()) {
            $payload['description'] = $this->description->value();
        }

        if ($this->orchestratorId->isPresent()) {
            $payload['orchestrator_id'] = $this->orchestratorId->value();
        }

        if ($this->members->isPresent()) {
            $payload['members'] = array_map(
                static fn (TeamMemberCreateRequest $member): array => $member->toArray(),
                $this->memberList(),
            );
        }

        return $payload;
    }

    /**
     * @return list<TeamMemberCreateRequest>
     */
    private function memberList(): array
    {
        $value = $this->members->value();

        if (! is_array($value)) {
            throw new \LogicException('TeamUpdateRequest field "members" must hold a list of TeamMemberCreateRequest instances.');
        }

        $members = [];

        foreach ($value as $item) {
            if (! $item instanceof TeamMemberCreateRequest) {
                throw new \LogicException('TeamUpdateRequest field "members" must hold a list of TeamMemberCreateRequest instances.');
            }

            $members[] = $item;
        }

        return $members;
    }
}
