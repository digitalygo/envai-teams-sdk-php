<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Team;

/**
 * Payload to create a team. `name` and `orchestrator_id` are required;
 * `members` is optional and ordered, with `position` derived from the array
 * order. `user_id` and per-member `position` and `user_id` are prohibited.
 */
final readonly class TeamCreateRequest
{
    /**
     * @param  list<TeamMemberCreateRequest>  $members
     */
    public function __construct(
        public string $name,
        public string $orchestratorId,
        public ?string $description = null,
        public array $members = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'name' => $this->name,
            'orchestrator_id' => $this->orchestratorId,
        ];

        if ($this->description !== null) {
            $payload['description'] = $this->description;
        }

        if ($this->members !== []) {
            $payload['members'] = array_map(
                static fn (TeamMemberCreateRequest $member): array => $member->toArray(),
                $this->members,
            );
        }

        return $payload;
    }
}
