<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Reporting;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;
use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use InvalidArgumentException;

final class ReportingWire
{
    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function objectArray(array $data, string $key, string $context): array
    {
        $value = Wire::presentOrFail($data, $key, $context);

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new InvalidArgumentException(sprintf('%s payload field "%s" must be an object.', $context, $key));
        }

        $result = [];

        foreach ($value as $name => $item) {
            if (! is_string($name)) {
                throw new InvalidArgumentException(sprintf('%s payload field "%s" must be an object.', $context, $key));
            }

            $result[$name] = $item;
        }

        return $result;
    }

    /** @param array<string, mixed> $data */
    public static function jsonObject(array $data, string $key, string $context): JsonObject
    {
        return new JsonObject(self::objectArray($data, $key, $context));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<mixed>
     */
    public static function listArray(array $data, string $key, string $context): array
    {
        return self::listFromValue(Wire::presentOrFail($data, $key, $context), $key, $context);
    }

    /** @param array<string, mixed> $data */
    public static function jsonArray(array $data, string $key, string $context): JsonArray
    {
        return new JsonArray(self::listArray($data, $key, $context));
    }

    /** @param array<string, mixed> $data */
    public static function requiredNullableJsonArray(array $data, string $key, string $context): ?JsonArray
    {
        $value = Wire::presentOrFail($data, $key, $context);

        return $value === null ? null : new JsonArray(self::listFromValue($value, $key, $context));
    }

    /** @param array<string, mixed> $data */
    public static function requiredNullableString(array $data, string $key, string $context): ?string
    {
        $value = Wire::presentOrFail($data, $key, $context);

        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            throw new InvalidArgumentException(sprintf('%s payload field "%s" must be a string or null, got %s.', $context, $key, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * @template T of ArrayHydratable
     *
     * @param  array<string, mixed>  $data
     * @param  class-string<T>  $class
     * @return list<T>
     */
    public static function modelList(array $data, string $key, string $context, string $class): array
    {
        $result = [];

        foreach (self::listArray($data, $key, $context) as $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException(sprintf('%s payload field "%s" must contain objects.', $context, $key));
            }

            $result[] = $class::fromArray(self::objectArray(['item' => $item], 'item', $context));
        }

        return $result;
    }

    /**
     * @return list<mixed>
     */
    private static function listFromValue(mixed $value, string $key, string $context): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException(sprintf('%s payload field "%s" must be an array.', $context, $key));
        }

        return $value;
    }
}
