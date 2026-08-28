<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Sync\Planner;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Sync\State\ResourceType;

/**
 * Normalizes desired, last-applied and remote payloads into one canonical
 * shape so the planner can compare them and the reconciler can persist
 * them deterministically.
 *
 * Rules:
 *
 * - server-only bookkeeping keys (id, user_id, created_at, updated_at)
 *   are stripped;
 * - JsonObject/JsonArray wrappers are unwrapped recursively;
 * - agent tool name sets (tools_enabled, external_tools_enabled) are
 *   sorted and deduplicated by name, because they are semantically
 *   unordered, mirroring the manifest hasher;
 * - optional fields are canonicalized as absent: a missing key, an
 *   explicit null and an empty container all normalize away, because the
 *   remote DTOs always emit those keys while the definitions omit them
 *   when the frontmatter is absent;
 * - team agent references (orchestrator_id, members[].agent_definition_id)
 *   are rewritten from remote ids to local keys through the reverse map
 *   supplied by the caller, so a remote team payload becomes comparable
 *   with the identity-mapped desired payload covered by the manifest
 *   hash. Unmapped ids are kept as-is.
 */
final class PayloadNormalizer
{
    /**
     * @param  array<string, string>  $agentIdToLocalKey  Remote agent id to local agent key.
     * @return array<string, mixed>
     */
    public static function normalize(ResourceType $type, mixed $payload, array $agentIdToLocalKey = []): array
    {
        $payload = self::unwrap($payload);

        if (! is_array($payload)) {
            return [];
        }

        $payload = self::stripServerKeys(self::stringKeyed($payload));

        return match ($type) {
            ResourceType::Tool => self::normalizeTool($payload),
            ResourceType::Agent => self::normalizeAgent($payload),
            ResourceType::Team => self::normalizeTeam($payload, $agentIdToLocalKey),
        };
    }

    /**
     * Keeps only string keys, matching the shape json_decode produces
     * for a JSON object.
     *
     * @param  array<mixed, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $payload): array
    {
        $result = [];

        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalizeTool(array $payload): array
    {
        if (array_key_exists('description', $payload) && $payload['description'] === null) {
            unset($payload['description']);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function normalizeAgent(array $payload): array
    {
        foreach (['tools_enabled', 'external_tools_enabled'] as $key) {
            if (array_key_exists($key, $payload)) {
                $payload[$key] = self::sortedUniqueToolEntries($payload[$key]);
            }
        }

        foreach (['description', 'model', 'tools_enabled', 'external_tools_enabled', 'permissions', 'config'] as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === []) {
                unset($payload[$key]);
            }
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $agentIdToLocalKey
     * @return array<string, mixed>
     */
    private static function normalizeTeam(array $payload, array $agentIdToLocalKey): array
    {
        if (array_key_exists('orchestrator_id', $payload)) {
            $orchestratorId = $payload['orchestrator_id'];
            $payload['orchestrator_id'] = is_string($orchestratorId)
                ? ($agentIdToLocalKey[$orchestratorId] ?? $orchestratorId)
                : $orchestratorId;
        }

        if (isset($payload['members']) && is_array($payload['members'])) {
            $members = [];

            foreach ($payload['members'] as $member) {
                if (! is_array($member)) {
                    $members[] = $member;

                    continue;
                }

                if (array_key_exists('agent_definition_id', $member)) {
                    $memberId = $member['agent_definition_id'];

                    if (is_string($memberId)) {
                        $member['agent_definition_id'] = $agentIdToLocalKey[$memberId] ?? $memberId;
                    }
                }

                // Server-side enrichment of member records: the agent name
                // duplicates agent_definition_id and the position mirrors
                // the array order, so both normalize away for comparison.
                unset($member['name'], $member['position']);

                $members[] = $member;
            }

            $payload['members'] = $members;
        }

        if (array_key_exists('description', $payload) && $payload['description'] === null) {
            unset($payload['description']);
        }

        return $payload;
    }

    /**
     * Sorts a `[{name: ...}, ...]` tool reference list by name and drops
     * duplicates, preserving the first occurrence of each name.
     *
     * @return list<array<string, mixed>>
     */
    private static function sortedUniqueToolEntries(mixed $entries): array
    {
        if (! is_array($entries)) {
            return [];
        }

        $byName = [];
        $order = [];

        foreach ($entries as $entry) {
            if (! is_array($entry) || ! isset($entry['name']) || ! is_string($entry['name'])) {
                continue;
            }

            if (! array_key_exists($entry['name'], $byName)) {
                $byName[$entry['name']] = self::stringKeyedEntry($entry);
                $order[] = $entry['name'];
            }
        }

        sort($order, SORT_STRING);

        $result = [];

        foreach ($order as $name) {
            $result[] = $byName[$name];
        }

        return $result;
    }

    /**
     * Keeps only string keys of a tool reference entry so the map value
     * is a normalized object.
     *
     * @param  array<array-key, mixed>  $entry
     * @return array<string, mixed>
     */
    private static function stringKeyedEntry(array $entry): array
    {
        $normalized = [];

        foreach ($entry as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function stripServerKeys(array $payload): array
    {
        foreach (['id', 'user_id', 'created_at', 'updated_at'] as $key) {
            unset($payload[$key]);
        }

        return $payload;
    }

    private static function unwrap(mixed $value): mixed
    {
        if ($value instanceof JsonObject) {
            return array_map(self::unwrap(...), $value->toArray());
        }

        if ($value instanceof JsonArray) {
            return array_map(self::unwrap(...), $value->toArray());
        }

        if (is_array($value)) {
            return array_map(self::unwrap(...), $value);
        }

        return $value;
    }
}
