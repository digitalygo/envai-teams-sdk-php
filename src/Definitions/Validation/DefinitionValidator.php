<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Validation;

use Digitalygo\Envai\Definitions\Agent\AgentDefinition;
use Digitalygo\Envai\Definitions\Agent\AgentMode;
use Digitalygo\Envai\Definitions\Team\TeamDefinition;
use Digitalygo\Envai\Definitions\Tool\ToolDefinition;
use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * Validates compiled definitions against the semantic rules of the
 * platform contract and the local layout:
 *
 * - agents: body non-empty, remote name present and string, no
 *   `max_tokens` in config at any nesting depth. Frontmatter format and
 *   mode are enforced at parse time by AgentMarkdownCompiler because the
 *   typed AgentDefinition cannot represent an unsupported format or an
 *   invalid mode.
 * - tools: name pattern, description string, schema root object,
 *   timeout within 1..600, schema JSON-serializable.
 * - teams: orchestrator exists and is primary, members exist, are
 *   distinct, exclude the orchestrator and are subagents, roles are
 *   string or null.
 * - cross-references: client tools referenced by agents must exist in
 *   the tool registry; server tools are opaque and not validated; the
 *   model must be in the catalog when a catalog is supplied.
 * - manifest level: unique local keys and unique remote names per type.
 *
 * Every method returns a list of DefinitionViolation; an empty list
 * means the input is valid.
 */
final class DefinitionValidator
{
    /**
     * Runs all validation groups and returns the concatenated violations.
     *
     * @param  array<string, AgentDefinition>|list<AgentDefinition>  $agents
     * @param  array<string, ToolDefinition>|list<ToolDefinition>  $tools
     * @param  array<string, TeamDefinition>|list<TeamDefinition>  $teams
     * @param  list<string>|null  $catalogModels  Model ids available in the catalog; null skips the model check.
     * @return list<DefinitionViolation>
     */
    public function validate(array $agents, array $tools, array $teams, ?array $catalogModels = null): array
    {
        return [
            ...$this->validateAgents($agents),
            ...$this->validateTools($tools),
            ...$this->validateTeams($teams, $agents),
            ...$this->validateCrossReferences($agents, $tools, $catalogModels),
            ...$this->validateManifestLevel($agents, $tools, $teams),
        ];
    }

    /**
     * @param  array<string, AgentDefinition>|list<AgentDefinition>  $agents
     * @return list<DefinitionViolation>
     */
    public function validateAgents(array $agents): array
    {
        $violations = [];

        foreach ($agents as $agent) {
            if (trim($agent->systemPrompt) === '') {
                $violations[] = new DefinitionViolation(
                    $agent->localKey,
                    'body',
                    'agent.empty_body',
                    sprintf('Agent "%s" has an empty markdown body.', $agent->localKey),
                );
            }

            if (! $agent->hasFrontmatter('name')) {
                $violations[] = new DefinitionViolation(
                    $agent->localKey,
                    'name',
                    'agent.name_missing',
                    sprintf('Agent "%s" is missing the remote name in its frontmatter.', $agent->localKey),
                );
            } elseif ($agent->name === '') {
                $violations[] = new DefinitionViolation(
                    $agent->localKey,
                    'name',
                    'agent.name_invalid',
                    sprintf('Agent "%s" remote name must be a non-empty string.', $agent->localKey),
                );
            }

            if (self::containsMaxTokens($agent->config)) {
                $violations[] = new DefinitionViolation(
                    $agent->localKey,
                    'config.max_tokens',
                    'agent.max_tokens_forbidden',
                    sprintf('Agent "%s" config must not contain "max_tokens" at any nesting depth.', $agent->localKey),
                );
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, ToolDefinition>|list<ToolDefinition>  $tools
     * @return list<DefinitionViolation>
     */
    public function validateTools(array $tools): array
    {
        $violations = [];

        foreach ($tools as $tool) {
            if (preg_match('/^[a-z][a-z0-9_]{0,254}$/', $tool->localKey) !== 1) {
                $violations[] = new DefinitionViolation(
                    $tool->localKey,
                    'name',
                    'tool.invalid_name',
                    sprintf('Tool "%s" name must match ^[a-z][a-z0-9_]{0,254}$.', $tool->localKey),
                );
            }

            if (($tool->parameters->get('type') ?? null) !== 'object') {
                $violations[] = new DefinitionViolation(
                    $tool->localKey,
                    'parameters.type',
                    'tool.schema_root_not_object',
                    sprintf('Tool "%s" schema root type must be "object".', $tool->localKey),
                );
            }

            if ($tool->timeoutSeconds < 1 || $tool->timeoutSeconds > 600) {
                $violations[] = new DefinitionViolation(
                    $tool->localKey,
                    'timeout_seconds',
                    'tool.timeout_out_of_range',
                    sprintf('Tool "%s" timeout_seconds must be between 1 and 600.', $tool->localKey),
                );
            }

            if (! self::isJsonSerializable($tool->parameters->toArray())) {
                $violations[] = new DefinitionViolation(
                    $tool->localKey,
                    'parameters',
                    'tool.schema_not_serializable',
                    sprintf('Tool "%s" schema is not JSON-serializable.', $tool->localKey),
                );
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, TeamDefinition>|list<TeamDefinition>  $teams
     * @param  array<string, AgentDefinition>|list<AgentDefinition>  $agents
     * @return list<DefinitionViolation>
     */
    public function validateTeams(array $teams, array $agents): array
    {
        $agentsByKey = [];

        foreach ($agents as $agent) {
            $agentsByKey[$agent->localKey] = $agent;
        }

        $violations = [];

        foreach ($teams as $team) {
            $orchestrator = $agentsByKey[$team->orchestratorKey] ?? null;

            if ($orchestrator === null) {
                $violations[] = new DefinitionViolation(
                    $team->localKey,
                    'orchestrator',
                    'team.orchestrator_missing',
                    sprintf('Team "%s" references unknown orchestrator agent "%s".', $team->localKey, $team->orchestratorKey),
                );
            } elseif ($orchestrator->mode !== AgentMode::Primary) {
                $violations[] = new DefinitionViolation(
                    $team->localKey,
                    'orchestrator',
                    'team.orchestrator_not_primary',
                    sprintf('Team "%s" orchestrator agent "%s" must be mode primary.', $team->localKey, $team->orchestratorKey),
                );
            }

            $memberKeys = [];

            foreach ($team->members as $member) {
                $memberAgent = $agentsByKey[$member->localAgentKey] ?? null;

                if ($memberAgent === null) {
                    $violations[] = new DefinitionViolation(
                        $team->localKey,
                        'members',
                        'team.member_missing',
                        sprintf('Team "%s" references unknown member agent "%s".', $team->localKey, $member->localAgentKey),
                    );
                }

                if ($member->localAgentKey === $team->orchestratorKey) {
                    $violations[] = new DefinitionViolation(
                        $team->localKey,
                        'members',
                        'team.orchestrator_is_member',
                        sprintf('Team "%s" orchestrator agent "%s" must not be a member.', $team->localKey, $member->localAgentKey),
                    );
                } elseif ($memberAgent !== null && $memberAgent->mode !== AgentMode::Subagent) {
                    $violations[] = new DefinitionViolation(
                        $team->localKey,
                        'members',
                        'team.member_not_subagent',
                        sprintf('Team "%s" member agent "%s" must be mode subagent.', $team->localKey, $member->localAgentKey),
                    );
                }

                if (in_array($member->localAgentKey, $memberKeys, true)) {
                    $violations[] = new DefinitionViolation(
                        $team->localKey,
                        'members',
                        'team.member_duplicate',
                        sprintf('Team "%s" lists member agent "%s" more than once.', $team->localKey, $member->localAgentKey),
                    );
                }

                $memberKeys[] = $member->localAgentKey;
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, AgentDefinition>|list<AgentDefinition>  $agents
     * @param  array<string, ToolDefinition>|list<ToolDefinition>  $tools
     * @param  list<string>|null  $catalogModels
     * @return list<DefinitionViolation>
     */
    public function validateCrossReferences(array $agents, array $tools, ?array $catalogModels = null): array
    {
        $toolKeys = [];

        foreach ($tools as $tool) {
            $toolKeys[$tool->localKey] = true;
        }

        $violations = [];

        foreach ($agents as $agent) {
            foreach ($agent->clientTools as $toolName) {
                if (! isset($toolKeys[$toolName])) {
                    $violations[] = new DefinitionViolation(
                        $agent->localKey,
                        'tools.client',
                        'agent.client_tool_missing',
                        sprintf(
                            'Agent "%s" references client tool "%s" which is not registered in the tool registry.',
                            $agent->localKey,
                            $toolName,
                        ),
                    );
                }
            }

            if ($catalogModels !== null && $agent->model !== null && ! in_array($agent->model, $catalogModels, true)) {
                $violations[] = new DefinitionViolation(
                    $agent->localKey,
                    'model',
                    'agent.model_not_in_catalog',
                    sprintf('Agent "%s" model "%s" is not available in the catalog.', $agent->localKey, $agent->model),
                );
            }
        }

        return $violations;
    }

    /**
     * @param  array<string, AgentDefinition>|list<AgentDefinition>  $agents
     * @param  array<string, ToolDefinition>|list<ToolDefinition>  $tools
     * @param  array<string, TeamDefinition>|list<TeamDefinition>  $teams
     * @return list<DefinitionViolation>
     */
    public function validateManifestLevel(array $agents, array $tools, array $teams): array
    {
        $violations = [];

        $violations = [
            ...self::duplicates($agents, 'agent', 'agent.name_duplicate', 'name', self::remoteNameExtractor()),
            ...self::duplicates($tools, 'tool', 'tool.name_duplicate', 'name', self::remoteNameExtractor()),
            ...self::duplicates($teams, 'team', 'team.name_duplicate', 'name', self::remoteNameExtractor()),
            ...self::duplicates($agents, 'agent', 'agent.local_key_duplicate', 'local_key', static fn (AgentDefinition|TeamDefinition|ToolDefinition $definition): string => $definition->localKey),
            ...self::duplicates($tools, 'tool', 'tool.local_key_duplicate', 'local_key', static fn (AgentDefinition|TeamDefinition|ToolDefinition $definition): string => $definition->localKey),
            ...self::duplicates($teams, 'team', 'team.local_key_duplicate', 'local_key', static fn (AgentDefinition|TeamDefinition|ToolDefinition $definition): string => $definition->localKey),
        ];

        return $violations;
    }

    /**
     * The remote name of a definition; for tools it is the local key.
     *
     * @return callable(AgentDefinition|TeamDefinition|ToolDefinition): string
     */
    private static function remoteNameExtractor(): callable
    {
        return static function (AgentDefinition|TeamDefinition|ToolDefinition $definition): string {
            if ($definition instanceof ToolDefinition) {
                return $definition->localKey;
            }

            return $definition->name;
        };
    }

    /**
     * Reports definitions that share the same remote name or local key
     * within one type.
     *
     * @param  array<string, AgentDefinition>|list<AgentDefinition>|array<string, ToolDefinition>|list<ToolDefinition>|array<string, TeamDefinition>|list<TeamDefinition>  $definitions
     * @param  callable(AgentDefinition|ToolDefinition|TeamDefinition): string  $valueOf
     * @return list<DefinitionViolation>
     */
    private static function duplicates(array $definitions, string $type, string $code, string $field, callable $valueOf): array
    {
        $seen = [];
        $violations = [];

        foreach ($definitions as $definition) {
            $value = $valueOf($definition);
            $identity = spl_object_id($definition);

            if (isset($seen[$value]) && $seen[$value] !== $identity) {
                $violations[] = new DefinitionViolation(
                    $definition->localKey,
                    $field,
                    $code,
                    sprintf('Duplicate %s %s "%s"; the value must be unique within the manifest.', $type, $field, $value),
                );
            }

            $seen[$value] = $identity;
        }

        return $violations;
    }

    /**
     * Recursively searches a JSON-compatible structure for the exact key
     * `max_tokens` at any nesting depth.
     */
    private static function containsMaxTokens(mixed $value): bool
    {
        if ($value instanceof JsonObject) {
            return self::containsMaxTokensInMap($value->toArray());
        }

        if ($value instanceof JsonArray) {
            return self::containsMaxTokensInMap($value->toArray());
        }

        if (is_array($value)) {
            return self::containsMaxTokensInMap($value);
        }

        return false;
    }

    /**
     * @param  array<mixed, mixed>  $data
     */
    private static function containsMaxTokensInMap(array $data): bool
    {
        foreach ($data as $key => $item) {
            if ($key === 'max_tokens' || self::containsMaxTokens($item)) {
                return true;
            }
        }

        return false;
    }

    private static function isJsonSerializable(mixed $value): bool
    {
        try {
            json_encode($value, JSON_THROW_ON_ERROR);

            return true;
        } catch (\JsonException) {
            return false;
        }
    }
}
