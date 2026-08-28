<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Sync\Fakes;

use Digitalygo\Envai\Sync\Planner\RemoteReader;

/**
 * Read-only fake of the remote Envai resources for planner tests. The
 * resource lists are plain SDK-shaped payload arrays; each list call
 * records itself so tests can assert how many times the remote was read.
 */
final class FakeRemoteReader implements RemoteReader
{
    /**
     * @var list<array<string, mixed>>
     */
    public array $tools = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $agents = [];

    /**
     * @var list<array<string, mixed>>
     */
    public array $teams = [];

    public int $toolsReads = 0;

    public int $agentsReads = 0;

    public int $teamsReads = 0;

    /**
     * @param  list<array<string, mixed>>  $tools
     * @param  list<array<string, mixed>>  $agents
     * @param  list<array<string, mixed>>  $teams
     */
    public function __construct(array $tools = [], array $agents = [], array $teams = [])
    {
        $this->tools = $tools;
        $this->agents = $agents;
        $this->teams = $teams;
    }

    public function listTools(): array
    {
        $this->toolsReads++;

        return $this->tools;
    }

    public function listAgents(): array
    {
        $this->agentsReads++;

        return $this->agents;
    }

    public function listTeams(): array
    {
        $this->teamsReads++;

        return $this->teams;
    }

    /**
     * @param  array<string, mixed>  $tool
     */
    public function addTool(array $tool): void
    {
        $this->tools[] = $tool;
    }

    /**
     * @param  array<string, mixed>  $agent
     */
    public function addAgent(array $agent): void
    {
        $this->agents[] = $agent;
    }

    /**
     * @param  array<string, mixed>  $team
     */
    public function addTeam(array $team): void
    {
        $this->teams[] = $team;
    }
}
