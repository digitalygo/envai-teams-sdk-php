<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Reconciler;

/**
 * Write-side view over the remote Envai resources: the mutations the
 * reconciler performs, plus a read used for post-ambiguity reconciliation.
 *
 * Every mutation returns the normalized remote payload of the resource as
 * observed after the mutation (SDK DTO toArray() shape), from which the
 * reconciler extracts the remote id, name and marker (for example
 * `updated_at`). Mutations are ambiguous and must never be retried
 * automatically by the caller.
 */
interface RemoteWriter
{
    /**
     * Registers a client tool; the payload is the UserToolCreateRequest
     * shape. Tools are addressed by name afterwards.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> The created tool as observed remotely.
     */
    public function createTool(array $payload): array;

    /**
     * Updates a client tool by name (PUT /tools/{name}).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateTool(string $name, array $payload): array;

    /**
     * Deletes a client tool by name (HTTP 204).
     */
    public function deleteTool(string $name): void;

    /**
     * Creates an agent definition; the payload is the AgentCreateRequest
     * shape.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createAgent(array $payload): array;

    /**
     * Updates an agent definition by remote id.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateAgent(string $agentId, array $payload): array;

    /**
     * Deletes an agent definition by remote id (HTTP 204).
     */
    public function deleteAgent(string $agentId): void;

    /**
     * Creates a team; the payload is the TeamCreateRequest shape with
     * remote agent definition ids already substituted by the reconciler.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createTeam(array $payload): array;

    /**
     * Updates a team by remote id.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateTeam(string $teamId, array $payload): array;

    /**
     * Deletes a team by remote id (HTTP 204).
     */
    public function deleteTeam(string $teamId): void;

    /**
     * Reads one remote resource for post-ambiguity reconciliation: tools
     * by name, agents and teams by id. Returns the normalized payload or
     * null when the resource does not exist. Must not throw for a plain
     * 404; transport failures may still throw.
     *
     * @param  string  $type  `tool`, `agent` or `team`.
     * @param  string  $nameOrId  Tool name, or agent/team remote id.
     * @return array<string, mixed>|null
     */
    public function readRemote(string $type, string $nameOrId): ?array;
}
