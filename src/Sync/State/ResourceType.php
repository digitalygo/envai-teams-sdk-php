<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\State;

/**
 * The three kinds of remote resources the sync core manages, matching the
 * local definitions layout (agents, teams, tools). The string values are
 * the stable identifiers used in operation ids (`tool:name`,
 * `agent:key`, `team:key`) and in the state store.
 */
enum ResourceType: string
{
    case Tool = 'tool';

    case Agent = 'agent';

    case Team = 'team';

    /**
     * The order in which upserts (create/update) must run: tools, then
     * agents, then teams, because agents reference tools by name and
     * teams reference agents by remote id.
     *
     * @return int 0 for tools, 1 for agents, 2 for teams.
     */
    public function upsertRank(): int
    {
        return match ($this) {
            self::Tool => 0,
            self::Agent => 1,
            self::Team => 2,
        };
    }

    /**
     * The order in which prune deletions must run: teams, then agents,
     * then tools (reverse of the upsert order, dependencies first).
     *
     * @return int 0 for teams, 1 for agents, 2 for tools.
     */
    public function deleteRank(): int
    {
        return match ($this) {
            self::Team => 0,
            self::Agent => 1,
            self::Tool => 2,
        };
    }
}
