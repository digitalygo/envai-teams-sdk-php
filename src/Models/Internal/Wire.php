<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Internal;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * Static typed reader over decoded JSON payloads, shared by every model
 * fromArray() factory.
 *
 * Each method validates the field type and throws an InvalidArgumentException
 * with a precise message listing the payload context and the offending field
 * when the wire value is missing or malformed. Values are never fabricated:
 * a required field that the schema marks required must be present and typed,
 * while optional fields fall back to their documented defaults (null, false
 * or an empty collection).
 *
 * Unknown keys are never read here: every model collects them into its own
 * $extra property for forward compatibility.
 */
final class Wire
{
    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $knownKeys
     * @return array<string, mixed>
     */
    public static function extra(array $data, array $knownKeys): array
    {
        return array_diff_key($data, array_flip($knownKeys));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function string(array $data, string $key, string $context): string
    {
        $value = self::presentOrFail($data, $key, $context);

        if (! is_string($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "%s" must be a string, got %s.',
                $context,
                $key,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function nullableString(array $data, string $key, string $context): ?string
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        if ($data[$key] === null) {
            return null;
        }

        return self::string($data, $key, $context);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function int(array $data, string $key, string $context): int
    {
        $value = self::presentOrFail($data, $key, $context);

        if (! is_int($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "%s" must be an integer, got %s.',
                $context,
                $key,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function nullableInt(array $data, string $key, string $context): ?int
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        if ($data[$key] === null) {
            return null;
        }

        return self::int($data, $key, $context);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function float(array $data, string $key, string $context): float
    {
        $value = self::presentOrFail($data, $key, $context);

        if (! is_int($value) && ! is_float($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "%s" must be a number, got %s.',
                $context,
                $key,
                get_debug_type($value),
            ));
        }

        return (float) $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function nullableFloat(array $data, string $key, string $context): ?float
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        if ($data[$key] === null) {
            return null;
        }

        return self::float($data, $key, $context);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function bool(array $data, string $key, string $context): bool
    {
        $value = self::presentOrFail($data, $key, $context);

        if (! is_bool($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "%s" must be a boolean, got %s.',
                $context,
                $key,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function boolDefault(array $data, string $key, string $context): bool
    {
        if (! array_key_exists($key, $data)) {
            return false;
        }

        return self::bool($data, $key, $context);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function stringList(array $data, string $key, string $context): array
    {
        $value = self::presentOrFail($data, $key, $context);

        if (! is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "%s" must be an array, got %s.',
                $context,
                $key,
                get_debug_type($value),
            ));
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s payload field "%s" must be a list of strings, got a non-string entry.',
                    $context,
                    $key,
                ));
            }

            $items[] = $item;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public static function stringListDefault(array $data, string $key, string $context): array
    {
        if (! array_key_exists($key, $data)) {
            return [];
        }

        return self::stringList($data, $key, $context);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function objectArray(array $data, string $key, string $context): array
    {
        $value = self::presentOrFail($data, $key, $context);

        if (! is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "%s" must be an object, got %s.',
                $context,
                $key,
                get_debug_type($value),
            ));
        }

        return self::stringKeyed($value, $key, $context);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function objectArrayDefault(array $data, string $key, string $context): array
    {
        if (! array_key_exists($key, $data)) {
            return [];
        }

        return self::objectArray($data, $key, $context);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public static function nullableObjectArray(array $data, string $key, string $context): ?array
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        if ($data[$key] === null) {
            return null;
        }

        return self::objectArray($data, $key, $context);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function jsonObject(array $data, string $key, string $context): JsonObject
    {
        return new JsonObject(self::objectArray($data, $key, $context));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function nullableJsonObject(array $data, string $key, string $context): ?JsonObject
    {
        $value = self::nullableObjectArray($data, $key, $context);

        return $value === null ? null : new JsonObject($value);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function jsonObjectOrArray(array $data, string $key, string $context): JsonObject|JsonArray
    {
        $value = self::presentOrFail($data, $key, $context);

        if (! is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "%s" must be an object or an array, got %s.',
                $context,
                $key,
                get_debug_type($value),
            ));
        }

        return self::wrapJsonValue($value, $key, $context);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function nullableJsonObjectOrArray(array $data, string $key, string $context): JsonObject|JsonArray|null
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        if ($data[$key] === null) {
            return null;
        }

        return self::jsonObjectOrArray($data, $key, $context);
    }

    /**
     * @template E of \BackedEnum
     *
     * @param  array<string, mixed>  $data
     * @param  class-string<E>  $enumClass
     * @return E
     */
    public static function enum(array $data, string $key, string $enumClass, string $context): \BackedEnum
    {
        $value = self::presentOrFail($data, $key, $context);

        if (! is_string($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "%s" must be a string, got %s.',
                $context,
                $key,
                get_debug_type($value),
            ));
        }

        $enum = $enumClass::tryFrom($value);

        if ($enum === null) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "%s" has unknown value "%s"; expected one of %s.',
                $context,
                $key,
                $value,
                implode(', ', self::enumValues($enumClass)),
            ));
        }

        return $enum;
    }

    /**
     * @template E of \BackedEnum
     *
     * @param  array<string, mixed>  $data
     * @param  class-string<E>  $enumClass
     * @return E|null
     */
    public static function nullableEnum(array $data, string $key, string $enumClass, string $context): ?\BackedEnum
    {
        if (! array_key_exists($key, $data)) {
            return null;
        }

        if ($data[$key] === null) {
            return null;
        }

        return self::enum($data, $key, $enumClass, $context);
    }

    /**
     * @template E of \BackedEnum
     *
     * @param  array<string, mixed>  $data
     * @param  class-string<E>  $enumClass
     * @return list<E>
     */
    public static function enumList(array $data, string $key, string $enumClass, string $context): array
    {
        $value = self::presentOrFail($data, $key, $context);

        if (! is_array($value)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "%s" must be an array, got %s.',
                $context,
                $key,
                get_debug_type($value),
            ));
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s payload field "%s" must be a list of strings, got a non-string entry.',
                    $context,
                    $key,
                ));
            }

            $enum = $enumClass::tryFrom($item);

            if ($enum === null) {
                throw new \InvalidArgumentException(sprintf(
                    '%s payload field "%s" has unknown value "%s"; expected one of %s.',
                    $context,
                    $key,
                    $item,
                    implode(', ', self::enumValues($enumClass)),
                ));
            }

            $items[] = $enum;
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function presentOrFail(array $data, string $key, string $context): mixed
    {
        if (! array_key_exists($key, $data)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload is missing required field "%s".',
                $context,
                $key,
            ));
        }

        return $data[$key];
    }

    /**
     * @param  array<mixed, mixed>  $value
     * @return array<string, mixed>
     */
    public static function stringKeyed(array $value, string $key, string $context): array
    {
        $result = [];

        foreach ($value as $itemKey => $item) {
            if (is_string($itemKey)) {
                $result[$itemKey] = $item;
            }
        }

        return $result;
    }

    /**
     * @param  array<mixed, mixed>  $value
     */
    private static function wrapJsonValue(array $value, string $key, string $context): JsonObject|JsonArray
    {
        if (array_is_list($value)) {
            return new JsonArray($value);
        }

        return new JsonObject(self::stringKeyed($value, $key, $context));
    }

    /**
     * @template E of \BackedEnum
     *
     * @param  class-string<E>  $enumClass
     * @return list<string>
     */
    private static function enumValues(string $enumClass): array
    {
        return array_map(
            static fn (\BackedEnum $case): string => (string) $case->value,
            $enumClass::cases(),
        );
    }
}
