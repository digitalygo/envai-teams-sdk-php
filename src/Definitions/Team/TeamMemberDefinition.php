<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Team;

/**
 * Reference to a subagent member of a team, identified by its local
 * agent key. The role is a team-scoped label, null when not set.
 */
final readonly class TeamMemberDefinition
{
    public function __construct(
        public string $localAgentKey,
        public ?string $role = null,
    ) {}
}
