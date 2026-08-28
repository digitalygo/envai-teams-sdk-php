<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Team;

use Digitalygo\Envai\Definitions\Agent\AgentMode;

/**
 * Immutable local definition of a team, compiled from a declarative PHP
 * file in the `envai/teams` directory. The local key must match the file
 * name without the `.php` extension. Members are ordered and the order is
 * preserved in the remote payload.
 */
final readonly class TeamDefinition
{
    /**
     * @param  list<TeamMemberDefinition>  $members  Ordered member list, excluding the orchestrator.
     */
    public function __construct(
        public string $localKey,
        public string $name,
        public ?string $description,
        public string $orchestratorKey,
        public array $members,
        private bool $hasDescription = false,
    ) {}

    /**
     * Builds the TeamCreateRequest-shaped remote payload. The local agent
     * keys are resolved to remote agent definition ids through the given
     * maps; a key without an id is a structural error.
     *
     * When the description was explicitly set (including an explicit
     * null) the key is emitted, otherwise it is omitted, so the payload
     * distinguishes null from absent. The description presence flag is
     * derived from the TeamBuilder: calling description() marks the field
     * as present.
     *
     * @param  array<string, string>  $agentIds  Map of local agent key to remote agent definition id.
     * @param  array<string, AgentMode>  $agentModes  Reserved for future mode-aware expansion; currently does not affect the output.
     * @return array<string, mixed>
     */
    public function toRemotePayload(array $agentIds, array $agentModes): array
    {
        $payload = [
            'name' => $this->name,
            'orchestrator_id' => $this->resolveId($agentIds, $this->orchestratorKey),
            'members' => array_map(
                fn (TeamMemberDefinition $member): array => [
                    'agent_definition_id' => $this->resolveId($agentIds, $member->localAgentKey),
                    'role' => $member->role,
                ],
                $this->members,
            ),
        ];

        if ($this->hasDescription) {
            $payload['description'] = $this->description;
        }

        return $payload;
    }

    /**
     * @param  array<string, string>  $agentIds
     */
    private function resolveId(array $agentIds, string $localKey): string
    {
        if (! array_key_exists($localKey, $agentIds)) {
            throw new \InvalidArgumentException(sprintf(
                'No agent id available for local agent key "%s" while building the remote payload of team "%s".',
                $localKey,
                $this->localKey,
            ));
        }

        return $agentIds[$localKey];
    }
}
