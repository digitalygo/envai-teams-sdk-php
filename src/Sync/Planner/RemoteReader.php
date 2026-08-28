<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Planner;

/**
 * Read-only view over the remote Envai resources of the caller. Each
 * payload is normalized like the SDK DTO toArray() shape: object keys in
 * snake_case, id/user_id/created_at/updated_at present, name present for
 * every kind. The planner matches tools by name and agents/teams by
 * stored remote id first, then by name for bootstrap.
 */
interface RemoteReader
{
    /**
     * Lists the caller's user tools.
     *
     * @return list<array<string, mixed>>
     */
    public function listTools(): array;

    /**
     * Lists the caller's agent definitions.
     *
     * @return list<array<string, mixed>>
     */
    public function listAgents(): array;

    /**
     * Lists the caller's teams.
     *
     * @return list<array<string, mixed>>
     */
    public function listTeams(): array;
}
