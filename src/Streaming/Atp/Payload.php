<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Atp;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Streaming\Events\ToolKind;
use Digitalygo\Envai\Streaming\Events\ToolSignature;
use Digitalygo\Envai\Streaming\Events\Usage;

/**
 * Internal typed readers for ATP payload validation.
 *
 * Every reader pushes a human readable violation onto the given list instead
 * of throwing, so AtpEventDecoder can report every broken field of an event
 * payload at once. Readers return null for an absent or malformed field; the
 * decoder only constructs the typed event when the violation list is empty.
 *
 * This class is not part of the public SDK contract.
 */
final class Payload
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $violations
     */
    public static function string(array $data, string $key, array &$violations, string $type): ?string
    {
        if (! array_key_exists($key, $data)) {
            $violations[] = sprintf('%s: payload field "%s" is required.', $type, $key);

            return null;
        }

        $value = $data[$key];

        if (! is_string($value)) {
            $violations[] = sprintf('%s: payload field "%s" must be a string, got %s.', $type, $key, get_debug_type($value));

            return null;
        }

        return $value;
    }

    /**
     * Optional field: absent or null yield null, any other non-string value
     * is a violation.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $violations
     */
    public static function nullableString(array $data, string $key, array &$violations, string $type): ?string
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return self::string($data, $key, $violations, $type);
    }

    /**
     * Required field that may legitimately be null (for example
     * `agent.started` `parent_id`).
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $violations
     */
    public static function requiredNullableString(array $data, string $key, array &$violations, string $type): ?string
    {
        if (! array_key_exists($key, $data)) {
            $violations[] = sprintf('%s: payload field "%s" is required.', $type, $key);

            return null;
        }

        if ($data[$key] === null) {
            return null;
        }

        return self::string($data, $key, $violations, $type);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $violations
     */
    public static function int(array $data, string $key, array &$violations, string $type): ?int
    {
        if (! array_key_exists($key, $data)) {
            $violations[] = sprintf('%s: payload field "%s" is required.', $type, $key);

            return null;
        }

        $value = $data[$key];

        if (! is_int($value)) {
            $violations[] = sprintf('%s: payload field "%s" must be an integer, got %s.', $type, $key, get_debug_type($value));

            return null;
        }

        return $value;
    }

    /**
     * Accepts both JSON integers and floats, mirroring the schema "number".
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $violations
     */
    public static function float(array $data, string $key, array &$violations, string $type): ?float
    {
        if (! array_key_exists($key, $data)) {
            $violations[] = sprintf('%s: payload field "%s" is required.', $type, $key);

            return null;
        }

        $value = $data[$key];

        if (! is_int($value) && ! is_float($value)) {
            $violations[] = sprintf('%s: payload field "%s" must be a number, got %s.', $type, $key, get_debug_type($value));

            return null;
        }

        return (float) $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $violations
     */
    public static function bool(array $data, string $key, array &$violations, string $type): ?bool
    {
        if (! array_key_exists($key, $data)) {
            $violations[] = sprintf('%s: payload field "%s" is required.', $type, $key);

            return null;
        }

        $value = $data[$key];

        if (! is_bool($value)) {
            $violations[] = sprintf('%s: payload field "%s" must be a boolean, got %s.', $type, $key, get_debug_type($value));

            return null;
        }

        return $value;
    }

    /**
     * A JSON object (not a list) as a raw array with string keys.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $violations
     * @return array<string, mixed>|null
     */
    public static function object(array $data, string $key, array &$violations, string $type): ?array
    {
        if (! array_key_exists($key, $data)) {
            $violations[] = sprintf('%s: payload field "%s" is required.', $type, $key);

            return null;
        }

        $value = $data[$key];

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            $violations[] = sprintf('%s: payload field "%s" must be an object, got %s.', $type, $key, get_debug_type($value));

            return null;
        }

        return self::stringKeyed($value);
    }

    /**
     * Reads the `args` payload field of `agent.tool.request` from the
     * decoded object tree, applying the documented alpha divergence: the
     * live gateway omits the `args` field entirely when the LLM passes no
     * arguments and signs the preimage over the empty object `{}`.
     *
     * Absent or null `args` therefore decode to an empty JsonObject
     * (object semantics, never a list). A present value must be a JSON
     * object; it is converted to a typed JsonObject whose nested values
     * preserve `{}` vs `[]` at every depth.
     *
     * @param  list<string>  $violations
     */
    public static function requestArgs(\stdClass $data, array &$violations, string $type): ?JsonObject
    {
        $fields = get_object_vars($data);

        if (! array_key_exists('args', $fields) || $fields['args'] === null) {
            return new JsonObject([]);
        }

        return self::treeObject($data, 'args', $violations, $type);
    }

    /**
     * Validates an args-like JSON object payload field against the
     * decoded object tree (json_decode with objects, not associative
     * arrays): the field must be present and a JSON object, not a list.
     * Empty containers keep their wire semantics, so `{}` is accepted and
     * `[]` is rejected, and the object is returned as a typed JsonObject
     * preserving nested `{}` vs `[]` at every depth.
     *
     * @param  list<string>  $violations
     */
    public static function treeObject(\stdClass $data, string $key, array &$violations, string $type): ?JsonObject
    {
        $fields = get_object_vars($data);

        if (! array_key_exists($key, $fields)) {
            $violations[] = sprintf('%s: payload field "%s" is required.', $type, $key);

            return null;
        }

        $value = $fields[$key];

        if (! $value instanceof \stdClass) {
            $violations[] = sprintf('%s: payload field "%s" must be an object, got %s.', $type, $key, get_debug_type($value));

            return null;
        }

        $result = [];

        foreach (get_object_vars($value) as $itemKey => $item) {
            $result[$itemKey] = self::typed($item);
        }

        return new JsonObject($result);
    }

    /**
     * Converts a value decoded from the JSON object tree into the typed
     * Json structure: JSON objects become JsonObject, JSON arrays become
     * JsonArray, recursively; scalars and null stay untouched. Because
     * stdClass distinguishes an empty object from an empty array, this
     * preserves `{}` vs `[]` at every depth, which plain associative
     * arrays cannot.
     */
    public static function typed(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $result = [];

            foreach (get_object_vars($value) as $key => $item) {
                $result[$key] = self::typed($item);
            }

            return new JsonObject($result);
        }

        if (is_array($value)) {
            $items = [];

            foreach ($value as $item) {
                $items[] = self::typed($item);
            }

            return new JsonArray($items);
        }

        return $value;
    }

    /**
     * Validates a nested Usage object. `costUsd`, `durationMs` and
     * `spriteMs` stay nullable because the alpha can emit incomplete usage.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $violations
     */
    public static function usage(array $data, string $key, array &$violations, string $type): ?Usage
    {
        $value = self::object($data, $key, $violations, $type);

        if ($value === null) {
            return null;
        }

        $context = $type.'.'.$key;
        $inputTokens = self::int($value, 'input_tokens', $violations, $context);
        $outputTokens = self::int($value, 'output_tokens', $violations, $context);
        $costUsd = self::nullableFloat($value, 'cost_usd', $violations, $context);
        $durationMs = self::nullableInt($value, 'duration_ms', $violations, $context);
        $spriteMs = self::nullableInt($value, 'sprite_ms', $violations, $context);

        if (
            $inputTokens === null
            || $outputTokens === null
        ) {
            return null;
        }

        return new Usage(
            inputTokens: $inputTokens,
            outputTokens: $outputTokens,
            costUsd: $costUsd,
            durationMs: $durationMs,
            spriteMs: $spriteMs,
            extra: self::extra($value, ['input_tokens', 'output_tokens', 'cost_usd', 'duration_ms', 'sprite_ms', 'sprite_ram_gb_ms', 'tool_calls']),
            spriteRamGbMs: self::nullableInt($value, 'sprite_ram_gb_ms', $violations, $context),
            toolCalls: self::nullableInt($value, 'tool_calls', $violations, $context),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $violations
     */
    public static function toolKind(array $data, string $key, array &$violations, string $type): ?ToolKind
    {
        $value = self::string($data, $key, $violations, $type);

        if ($value === null) {
            return null;
        }

        $kind = ToolKind::tryFrom($value);

        if ($kind === null) {
            $violations[] = sprintf('%s: payload field "%s" has unknown value "%s"; expected one of %s.', $type, $key, $value, implode(', ', array_map(static fn (ToolKind $k): string => $k->value, ToolKind::cases())));

            return null;
        }

        return $kind;
    }

    /**
     * Validates a nested ToolSignature object.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $violations
     */
    public static function signature(array $data, string $key, array &$violations, string $type): ?ToolSignature
    {
        $value = self::object($data, $key, $violations, $type);

        if ($value === null) {
            return null;
        }

        $context = $type.'.signature';
        $alg = self::string($value, 'alg', $violations, $context);
        $kid = self::string($value, 'kid', $violations, $context);
        $sig = self::string($value, 'sig', $violations, $context);

        if ($alg !== null && $alg !== 'EdDSA') {
            $violations[] = sprintf('%s: payload field "signature.alg" must be "EdDSA", got "%s".', $type, $alg);
        }

        if ($alg === null || $kid === null || $sig === null) {
            return null;
        }

        return new ToolSignature(
            alg: $alg,
            kid: $kid,
            sig: $sig,
            extra: self::extra($value, ['alg', 'kid', 'sig']),
        );
    }

    /**
     * Validates a tool result: string, object, array, number, boolean or
     * null, kept in its structured shape.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $violations
     */
    public static function result(array $data, string $key, array &$violations, string $type): JsonObject|JsonArray|string|int|float|bool|null
    {
        if (! array_key_exists($key, $data)) {
            $violations[] = sprintf('%s: payload field "%s" is required.', $type, $key);

            return null;
        }

        $value = $data[$key];

        if (is_array($value)) {
            if (array_is_list($value)) {
                return new JsonArray($value);
            }

            return new JsonObject(self::stringKeyed($value));
        }

        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        $violations[] = sprintf('%s: payload field "%s" must be an object, array, string, number, boolean or null, got %s.', $type, $key, get_debug_type($value));

        return null;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $violations
     */
    public static function nullableFloat(array $data, string $key, array &$violations, string $type): ?float
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return self::float($data, $key, $violations, $type);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $violations
     */
    public static function nullableInt(array $data, string $key, array &$violations, string $type): ?int
    {
        if (! array_key_exists($key, $data) || $data[$key] === null) {
            return null;
        }

        return self::int($data, $key, $violations, $type);
    }

    /**
     * The payload fields not consumed by the typed event.
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $knownKeys
     * @return array<string, mixed>
     */
    public static function extra(array $data, array $knownKeys): array
    {
        return array_diff_key($data, array_flip($knownKeys));
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    private static function stringKeyed(array $value): array
    {
        $result = [];

        foreach ($value as $itemKey => $item) {
            if (is_string($itemKey)) {
                $result[$itemKey] = $item;
            }
        }

        return $result;
    }
}
