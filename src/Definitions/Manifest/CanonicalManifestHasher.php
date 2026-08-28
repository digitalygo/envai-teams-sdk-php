<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Manifest;

use Digitalygo\Envai\Definitions\Agent\AgentDefinition;
use Digitalygo\Envai\Definitions\Team\TeamDefinition;
use Digitalygo\Envai\Models\Json\CanonicalJsonSerializer;
use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * Computes the canonical manifest hash per the binding algorithm: the
 * canonical document {format_version, tools, agents, teams} where every
 * definition is represented by its normalized remote payload, serialized
 * with RFC 8785 (CanonicalJsonSerializer) and hashed with sha256,
 * yielding a `sha256:<hex>` string.
 *
 * Normalization rules, precisely:
 *
 * - Tools: the UserToolCreateRequest payload (name, kind, description,
 *   parameters, timeout_seconds), always emitted in full.
 * - Agents: the AgentCreateRequest payload. The `tools_enabled` and
 *   `external_tools_enabled` tool name sets are sorted and deduplicated
 *   by name, because the sets are semantically unordered. Optional fields
 *   preserve the null/absent/empty distinctions of the local definition:
 *   an absent frontmatter key omits the payload key, an explicit null is
 *   emitted as null, and an explicitly present empty list or object stays
 *   as [] or {}. The markdown body has already been normalized to \n at
 *   parse time, so CRLF and LF inputs hash identically.
 * - Teams: the TeamCreateRequest payload shape with remote agent ids
 *   substituted by the local agent keys (identity map), since remote ids
 *   must never enter the manifest. Member order is preserved: it is
 *   semantically significant. The description key is omitted when the
 *   builder never set it, and emitted (possibly as null) when it did.
 *
 * The canonical document maps are always objects ({} when empty), the
 * RFC 8785 serializer sorts every member name by UTF-16 code units, and
 * the sha256 digest is computed over the resulting UTF-8 byte string.
 */
final class CanonicalManifestHasher
{
    /**
     * Hashes a single normalized definition embedded in the canonical
     * document shape, with the other resource maps empty. The caller is
     * responsible for passing a normalized payload: for agents the tool
     * name sets must already be sorted and deduplicated, for teams the
     * agent references must already be local keys.
     *
     * @param  string  $type  One of `tool`, `agent` or `team`.
     * @param  array<string, mixed>  $normalizedDefinition
     */
    public function resourceHash(string $type, string $localKey, array $normalizedDefinition): string
    {
        if (! in_array($type, ['tool', 'agent', 'team'], true)) {
            throw new \InvalidArgumentException(sprintf('Unknown definition type "%s"; expected tool, agent or team.', $type));
        }

        $document = [
            'format_version' => '1',
            'tools' => new JsonObject($type === 'tool' ? [$localKey => $normalizedDefinition] : []),
            'agents' => new JsonObject($type === 'agent' ? [$localKey => $normalizedDefinition] : []),
            'teams' => new JsonObject($type === 'team' ? [$localKey => $normalizedDefinition] : []),
        ];

        return self::digest($document);
    }

    /**
     * Computes the manifest hash from the definitions stored in the
     * manifest. The stored hash value is ignored; it is recomputed from
     * the definitions so the result is always the canonical digest.
     */
    public function manifestHash(DefinitionManifest $manifest): string
    {
        $tools = [];
        $agents = [];
        $teams = [];

        foreach ($manifest->tools as $localKey => $tool) {
            $tools[$localKey] = $tool->toRemotePayload();
        }

        foreach ($manifest->agents as $localKey => $agent) {
            $agents[$localKey] = self::normalizeAgentPayload($agent);
        }

        foreach ($manifest->teams as $localKey => $team) {
            $teams[$localKey] = self::normalizeTeamPayload($team);
        }

        $document = [
            'format_version' => $manifest->formatVersion,
            'tools' => new JsonObject($tools),
            'agents' => new JsonObject($agents),
            'teams' => new JsonObject($teams),
        ];

        return self::digest($document);
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeAgentPayload(AgentDefinition $agent): array
    {
        $payload = $agent->toRemotePayload();

        if ($agent->hasFrontmatter('tools.server')) {
            $payload['tools_enabled'] = self::sortedUniqueToolNames($agent->serverTools);
        }

        if ($agent->hasFrontmatter('tools.client')) {
            $payload['external_tools_enabled'] = self::sortedUniqueToolNames($agent->clientTools);
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeTeamPayload(TeamDefinition $team): array
    {
        $identity = [$team->orchestratorKey => $team->orchestratorKey];

        foreach ($team->members as $member) {
            $identity[$member->localAgentKey] = $member->localAgentKey;
        }

        return $team->toRemotePayload($identity, []);
    }

    /**
     * Sorts a set of tool names and removes duplicates, producing the
     * canonical `[{name: ...}]` shape.
     *
     * @param  list<string>  $names
     * @return list<array{name: string}>
     */
    private static function sortedUniqueToolNames(array $names): array
    {
        $unique = array_values(array_unique($names));

        sort($unique, SORT_STRING);

        return array_map(static fn (string $name): array => ['name' => $name], $unique);
    }

    /**
     * @param  array<string, mixed>  $document
     */
    private static function digest(array $document): string
    {
        $canonical = CanonicalJsonSerializer::serialize($document);

        return 'sha256:'.hash('sha256', $canonical);
    }
}
