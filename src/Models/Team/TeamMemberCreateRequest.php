<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Team;

/**
 * Member reference accepted in team create and update payloads. `position` is
 * derived from the array order and is prohibited here; `user_id` is also
 * prohibited.
 */
final readonly class TeamMemberCreateRequest
{
    public function __construct(
        public string $agentDefinitionId,
        public ?string $role = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = ['agent_definition_id' => $this->agentDefinitionId];

        if ($this->role !== null) {
            $payload['role'] = $this->role;
        }

        return $payload;
    }
}
