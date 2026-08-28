<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Client;

use Digitalygo\Envai\Client\AgentsService;
use Digitalygo\Envai\Client\ModelsService;
use Digitalygo\Envai\Client\TeamsService;
use Digitalygo\Envai\Client\ToolsService;
use Digitalygo\Envai\Models\Agent\Agent;
use Digitalygo\Envai\Models\Team\Team;
use Digitalygo\Envai\Models\Tool\UserTool;
use Digitalygo\Envai\Sync\Planner\RemoteReader;

/**
 * RemoteReader over the real Envai HTTP client: lists the caller's user
 * tools, agent definitions and teams by hydrating the service DTOs and
 * serializing them with their toArray() shape (snake_case object keys,
 * id/user_id/created_at/updated_at present, name present for every kind),
 * which is exactly the payload shape the planner matches on.
 *
 * Every list call returns the complete collection the service exposes.
 * listModelIds() additionally reads the LLM model catalog for the
 * compiler's model validation: a failed catalog read returns null and the
 * caller (DefinitionsService) decides how to treat the unknown catalog
 * (fail closed), never throwing out of the reader.
 */
final class ClientRemoteReader implements RemoteReader
{
    public function __construct(
        private readonly ToolsService $tools,
        private readonly AgentsService $agents,
        private readonly TeamsService $teams,
        private readonly ModelsService $models,
    ) {}

    /**
     * Lists the caller's user tools.
     *
     * @return list<array<string, mixed>>
     */
    public function listTools(): array
    {
        $collection = $this->tools->list();

        return array_map(
            static fn (UserTool $tool): array => $tool->toArray(),
            $collection->items,
        );
    }

    /**
     * Lists the caller's agent definitions.
     *
     * @return list<array<string, mixed>>
     */
    public function listAgents(): array
    {
        $collection = $this->agents->list();

        return array_map(
            static fn (Agent $agent): array => $agent->toArray(),
            $collection->items,
        );
    }

    /**
     * Lists the caller's teams.
     *
     * @return list<array<string, mixed>>
     */
    public function listTeams(): array
    {
        $collection = $this->teams->list();

        return array_map(
            static fn (Team $team): array => $team->toArray(),
            $collection->items,
        );
    }

    /**
     * Reads the LLM model catalog and returns the model ids it exposes,
     * or null when the catalog cannot be read (transport or API failure).
     * The caller decides how to treat an unknown catalog; this reader
     * never blocks a sync on its own.
     *
     * @return list<string>|null
     */
    public function listModelIds(): ?array
    {
        try {
            $catalog = $this->models->listModels();
        } catch (\Throwable) {
            return null;
        }

        $ids = [];

        foreach ($catalog->data as $entry) {
            $ids[] = $entry->id();
        }

        return $ids;
    }
}
