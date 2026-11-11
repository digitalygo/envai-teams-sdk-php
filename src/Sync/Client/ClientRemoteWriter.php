<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Client;

use Digitalygo\Envai\Client\AgentsService;
use Digitalygo\Envai\Client\TeamsService;
use Digitalygo\Envai\Client\ToolsService;
use Digitalygo\Envai\Exceptions\NotFoundException;
use Digitalygo\Envai\Models\Agent\AgentCreateRequest;
use Digitalygo\Envai\Models\Agent\AgentMode;
use Digitalygo\Envai\Models\Agent\AgentTool;
use Digitalygo\Envai\Models\Agent\AgentUpdateRequest;
use Digitalygo\Envai\Models\Common\OptionalField;
use Digitalygo\Envai\Models\Team\TeamCreateRequest;
use Digitalygo\Envai\Models\Team\TeamMemberCreateRequest;
use Digitalygo\Envai\Models\Team\TeamUpdateRequest;
use Digitalygo\Envai\Models\Tool\UserToolCreateRequest;
use Digitalygo\Envai\Models\Tool\UserToolUpdateRequest;
use Digitalygo\Envai\Sync\Reconciler\RemoteNameReader;
use Digitalygo\Envai\Sync\Reconciler\RemoteWriter;

/**
 * RemoteWriter over the real Envai HTTP client: builds the
 * *CreateRequest/*UpdateRequest DTOs from the normalized payloads the
 * reconciler hands in and returns the DTO toArray() shape observed after
 * the mutation, so the remote id/updated_at flow back as markers.
 *
 * Addressing rules match the Reconciler contract: tool mutations are
 * keyed by NAME (tool names are not updatable), agent and team mutations
 * by REMOTE ID. readRemote maps a plain 404 (NotFoundException) to null
 * for post-ambiguity reconciliation; transport failures still throw.
 *
 * Request DTOs are dumb constructors: only their own known fields are
 * serialized, unknown keys in the normalized payload are dropped.
 */
final class ClientRemoteWriter implements RemoteNameReader, RemoteWriter
{
    public function __construct(
        private readonly ToolsService $tools,
        private readonly AgentsService $agents,
        private readonly TeamsService $teams,
    ) {}

    /**
     * Registers a client tool (POST /tools).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> The created tool as observed remotely.
     */
    public function createTool(array $payload): array
    {
        return $this->tools->create(self::toolCreateRequest($payload))->toArray();
    }

    /**
     * Updates a client tool by name (PUT /tools/{name}). Only the
     * updatable fields (description, parameters, timeout_seconds) are
     * serialized; name and kind are server-owned.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateTool(string $name, array $payload): array
    {
        return $this->tools->update($name, self::toolUpdateRequest($payload))->toArray();
    }

    /**
     * Deletes a client tool by name (DELETE /tools/{name}).
     */
    public function deleteTool(string $name): void
    {
        $this->tools->delete($name);
    }

    /**
     * Creates an agent definition (POST /agents).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createAgent(array $payload): array
    {
        return $this->agents->create(self::agentCreateRequest($payload))->toArray();
    }

    /**
     * Updates an agent definition by remote id (PUT /agents/{agent_id}).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateAgent(string $agentId, array $payload): array
    {
        return $this->agents->update($agentId, self::agentUpdateRequest($payload))->toArray();
    }

    /**
     * Deletes an agent definition by remote id (DELETE /agents/{agent_id}).
     */
    public function deleteAgent(string $agentId): void
    {
        $this->agents->delete($agentId);
    }

    /**
     * Creates a team (POST /teams). The payload already carries remote
     * agent definition ids rewritten by the reconciler.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function createTeam(array $payload): array
    {
        return $this->teams->create(self::teamCreateRequest($payload))->toArray();
    }

    /**
     * Updates a team by remote id (PUT /teams/{team_id}).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function updateTeam(string $teamId, array $payload): array
    {
        return $this->teams->update($teamId, self::teamUpdateRequest($payload))->toArray();
    }

    /**
     * Deletes a team by remote id (DELETE /teams/{team_id}).
     */
    public function deleteTeam(string $teamId): void
    {
        $this->teams->delete($teamId);
    }

    /**
     * Reads one remote resource for post-ambiguity reconciliation: tools
     * by name, agents and teams by remote id. A plain 404 maps to null;
     * transport failures may still throw.
     *
     * @param  string  $type  `tool`, `agent` or `team`.
     * @param  string  $name  Exact desired agent/team name.
     * @return array<string, mixed>|null
     */
    public function readRemoteByName(string $type, string $name): ?array
    {
        $collection = match ($type) {
            'agent' => $this->agents->list(),
            'team' => $this->teams->list(),
            default => throw new \InvalidArgumentException('Unique-name recovery supports agents and teams.'),
        };
        $matches = [];
        foreach ($collection->items as $resource) {
            if ($resource->name === $name) {
                $matches[] = $resource->toArray();
            }
        }

        return count($matches) === 1 ? $matches[0] : null;
    }

    /** @return array<string, mixed>|null */
    public function readRemote(string $type, string $nameOrId): ?array
    {
        try {
            return match ($type) {
                'tool' => $this->tools->get($nameOrId)->toArray(),
                'agent' => $this->agents->get($nameOrId)->toArray(),
                'team' => $this->teams->get($nameOrId)->toArray(),
                default => throw new \InvalidArgumentException(sprintf(
                    'Unknown remote resource type "%s"; expected tool, agent or team.',
                    $type,
                )),
            };
        } catch (NotFoundException $exception) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function toolCreateRequest(array $payload): UserToolCreateRequest
    {
        return new UserToolCreateRequest(
            name: self::stringField($payload, 'name'),
            parameters: self::arrayField($payload, 'parameters', []),
            kind: self::stringField($payload, 'kind'),
            description: self::nullableStringField($payload, 'description'),
            timeoutSeconds: self::intField($payload, 'timeout_seconds', 120),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function toolUpdateRequest(array $payload): UserToolUpdateRequest
    {
        return new UserToolUpdateRequest(
            description: self::optional($payload, 'description', self::nullableStringMapper(...)),
            parameters: self::optional($payload, 'parameters', self::arrayMapper('parameters')),
            timeoutSeconds: self::optional($payload, 'timeout_seconds', self::intMapper('timeout_seconds')),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function agentCreateRequest(array $payload): AgentCreateRequest
    {
        return new AgentCreateRequest(
            name: self::stringField($payload, 'name'),
            systemPrompt: self::stringField($payload, 'system_prompt'),
            mode: self::modeField($payload),
            description: self::nullableStringField($payload, 'description'),
            model: self::nullableStringField($payload, 'model'),
            toolsEnabled: self::agentToolList($payload['tools_enabled'] ?? []),
            externalToolsEnabled: self::agentToolList($payload['external_tools_enabled'] ?? []),
            permissions: self::arrayField($payload, 'permissions', []),
            canWriteKnowledge: self::boolField($payload, 'can_write_knowledge', false),
            canWriteSkills: self::boolField($payload, 'can_write_skills', false),
            config: self::arrayField($payload, 'config', []),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function agentUpdateRequest(array $payload): AgentUpdateRequest
    {
        return new AgentUpdateRequest(
            name: self::optional($payload, 'name', self::stringMapper('name')),
            description: self::optional($payload, 'description', self::nullableStringMapper(...)),
            systemPrompt: self::optional($payload, 'system_prompt', self::stringMapper('system_prompt')),
            model: self::optional($payload, 'model', self::nullableStringMapper(...)),
            mode: self::optional($payload, 'mode', self::modeMapper('mode')),
            toolsEnabled: self::optional($payload, 'tools_enabled', self::agentToolListMapper('tools_enabled')),
            externalToolsEnabled: self::optional($payload, 'external_tools_enabled', self::agentToolListMapper('external_tools_enabled')),
            permissions: self::optional($payload, 'permissions', self::arrayMapper('permissions')),
            canWriteKnowledge: self::optional($payload, 'can_write_knowledge', self::boolMapper('can_write_knowledge')),
            canWriteSkills: self::optional($payload, 'can_write_skills', self::boolMapper('can_write_skills')),
            config: self::optional($payload, 'config', self::arrayMapper('config')),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function teamCreateRequest(array $payload): TeamCreateRequest
    {
        return new TeamCreateRequest(
            name: self::stringField($payload, 'name'),
            orchestratorId: self::stringField($payload, 'orchestrator_id'),
            description: self::nullableStringField($payload, 'description'),
            members: self::teamMemberList($payload['members'] ?? []),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function teamUpdateRequest(array $payload): TeamUpdateRequest
    {
        return new TeamUpdateRequest(
            name: self::optional($payload, 'name', self::stringMapper('name')),
            description: self::optional($payload, 'description', self::nullableStringMapper(...)),
            orchestratorId: self::optional($payload, 'orchestrator_id', self::stringMapper('orchestrator_id')),
            members: self::optional($payload, 'members', self::teamMemberListMapper('members')),
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function modeField(array $payload): AgentMode
    {
        $mode = $payload['mode'] ?? 'subagent';

        if (! is_string($mode)) {
            throw new \InvalidArgumentException('Agent payload field "mode" must be a string.');
        }

        try {
            return AgentMode::from($mode);
        } catch (\ValueError $exception) {
            throw new \InvalidArgumentException(sprintf(
                'Agent payload field "mode" has unsupported value "%s".',
                $mode,
            ), previous: $exception);
        }
    }

    /**
     * @return list<AgentTool>
     */
    private static function agentToolList(mixed $entries): array
    {
        if (! is_array($entries)) {
            throw new \InvalidArgumentException('Agent payload "tools_enabled" must be a list of tool objects.');
        }

        $tools = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new \InvalidArgumentException('Agent payload tool entries must be objects.');
            }

            $tools[] = AgentTool::fromArray(self::stringKeyed($entry));
        }

        return $tools;
    }

    /**
     * @return list<TeamMemberCreateRequest>
     */
    private static function teamMemberList(mixed $entries): array
    {
        if (! is_array($entries)) {
            throw new \InvalidArgumentException('Team payload "members" must be a list of member objects.');
        }

        $members = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                throw new \InvalidArgumentException('Team payload member entries must be objects.');
            }

            $entry = self::stringKeyed($entry);
            $role = isset($entry['role']) ? self::nullableStringValue($entry['role'], 'role') : null;

            $members[] = new TeamMemberCreateRequest(
                self::stringField($entry, 'agent_definition_id'),
                $role,
            );
        }

        return $members;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Payload field "%s" must be a string.', $key));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function nullableStringField(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return self::nullableStringValue($value, $key);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $default
     * @return array<string, mixed>
     */
    private static function arrayField(array $payload, string $key, array $default): array
    {
        return self::arrayValue($payload[$key] ?? $default, $key);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function intField(array $payload, string $key, int $default): int
    {
        return self::intValue($payload[$key] ?? $default, $key);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function boolField(array $payload, string $key, bool $default): bool
    {
        return self::boolValue($payload[$key] ?? $default, $key);
    }

    /**
     * Wraps a payload field in an OptionalField when the key is present,
     * applying the value mapper; absent keys stay absent so the update
     * request serializes only the fields the payload carries.
     *
     * @param  array<string, mixed>  $payload
     * @param  callable(mixed): mixed  $map
     */
    private static function optional(array $payload, string $key, callable $map): OptionalField
    {
        if (! array_key_exists($key, $payload)) {
            return OptionalField::absent();
        }

        return OptionalField::of($map($payload[$key]));
    }

    private static function stringMapper(string $key): \Closure
    {
        return static fn (mixed $value): string => self::stringValue($value, $key);
    }

    private static function nullableStringMapper(mixed $value): ?string
    {
        return self::nullableStringValue($value, 'value');
    }

    private static function arrayMapper(string $key): \Closure
    {
        return static fn (mixed $value): array => self::arrayValue($value, $key);
    }

    private static function intMapper(string $key): \Closure
    {
        return static fn (mixed $value): int => self::intValue($value, $key);
    }

    private static function boolMapper(string $key): \Closure
    {
        return static fn (mixed $value): bool => self::boolValue($value, $key);
    }

    private static function modeMapper(string $key): \Closure
    {
        return static function (mixed $value) use ($key): AgentMode {
            if (! is_string($value)) {
                throw new \InvalidArgumentException(sprintf('Payload field "%s" must be a string.', $key));
            }

            try {
                return AgentMode::from($value);
            } catch (\ValueError $exception) {
                throw new \InvalidArgumentException(sprintf(
                    'Payload field "%s" has unsupported mode value "%s".',
                    $key,
                    $value,
                ), previous: $exception);
            }
        };
    }

    private static function agentToolListMapper(string $key): \Closure
    {
        return static fn (mixed $value): array => self::agentToolList($value);
    }

    private static function teamMemberListMapper(string $key): \Closure
    {
        return static fn (mixed $value): array => self::teamMemberList($value);
    }

    private static function stringValue(mixed $value, string $key): string
    {
        if (! is_string($value)) {
            throw new \InvalidArgumentException(sprintf('Payload field "%s" must be a string.', $key));
        }

        return $value;
    }

    private static function nullableStringValue(mixed $value, string $key): ?string
    {
        if ($value === null) {
            return null;
        }

        return self::stringValue($value, $key);
    }

    /**
     * @return array<string, mixed>
     */
    /**
     * @return array<string, mixed>
     */
    private static function arrayValue(mixed $value, string $key): array
    {
        if (! is_array($value)) {
            throw new \InvalidArgumentException(sprintf('Payload field "%s" must be an array.', $key));
        }

        return self::stringKeyed($value);
    }

    private static function intValue(mixed $value, string $key): int
    {
        if (! is_int($value)) {
            throw new \InvalidArgumentException(sprintf('Payload field "%s" must be an integer.', $key));
        }

        return $value;
    }

    private static function boolValue(mixed $value, string $key): bool
    {
        if (! is_bool($value)) {
            throw new \InvalidArgumentException(sprintf('Payload field "%s" must be a boolean.', $key));
        }

        return $value;
    }

    /**
     * Keeps only string keys, matching the shape json_decode produces.
     *
     * @param  array<mixed, mixed>  $data
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $data): array
    {
        $result = [];

        foreach ($data as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }
}
