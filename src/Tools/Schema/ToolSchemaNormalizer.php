<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Schema;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;

/** Shared schema authoring normalization for definitions, HTTP and execution. */
final class ToolSchemaNormalizer
{
    /** @param array<array-key, mixed> $schema */
    public static function normalize(array $schema): \stdClass
    {
        self::assertJson($schema);

        return self::schemaObject($schema, '2020-12');
    }

    private static function assertJson(mixed $value, int $depth = 0): void
    {
        if ($depth > 512) {
            throw new \InvalidArgumentException('The schema is too deeply nested or cyclic.');
        }
        if ($value instanceof JsonObject || $value instanceof JsonArray) {
            $value = $value->data;
        } elseif ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $item) {
                if (is_string($key) && ! mb_check_encoding($key, 'UTF-8')) {
                    throw new \InvalidArgumentException('Schema member names must be valid UTF-8.');
                }
                self::assertJson($item, $depth + 1);
            }
        } elseif ((is_float($value) && ! is_finite($value)) || (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) || ($value !== null && ! is_scalar($value))) {
            throw new \InvalidArgumentException('Schemas must contain only valid JSON values.');
        }
    }

    /** @param array<array-key, mixed> $schema
     * @return array<array-key, mixed>
     */
    public static function forPayload(array $schema): array
    {
        return self::objectPayload(self::normalize($schema));
    }

    /** @return array<array-key, mixed> */
    public static function objectPayload(\stdClass $object): array
    {
        return array_map(self::payloadValue(...), get_object_vars($object));
    }

    /** Keep existing array APIs, wrapping only ambiguous JSON objects. */
    public static function payloadValue(mixed $value): mixed
    {
        if ($value instanceof JsonObject) {
            $value = (object) $value->data;
        }
        if ($value instanceof \stdClass) {
            $members = self::objectPayload($value);

            return $members === [] || array_is_list($members) ? new JsonObject($members) : $members;
        }
        if ($value instanceof JsonArray) {
            $value = $value->data;
        }

        return is_array($value) ? array_map(self::payloadValue(...), $value) : $value;
    }

    public static function draft(mixed $uri, string $default = '2020-12'): string
    {
        if ($uri === null) {
            return $default;
        }
        if (! is_string($uri) || ! preg_match('~^https?://json-schema\.org/(draft-0[67]|draft/(?:2019-09|2020-12))/schema#?$~D', $uri, $match)) {
            throw new \InvalidArgumentException('Only the bundled JSON Schema drafts are supported.');
        }

        return match ($match[1]) {
            'draft-06' => '06',
            'draft-07' => '07',
            'draft/2019-09' => '2019-09',
            default => '2020-12',
        };
    }

    private static function schemaKeyword(int|string $keyword, string $draft): bool
    {
        if (in_array($keyword, ['properties', 'patternProperties', 'definitions', 'dependencies', 'additionalProperties', 'propertyNames', 'contains', 'not', 'items', 'allOf', 'anyOf', 'oneOf'], true)) {
            return true;
        }
        if ($keyword === 'additionalItems') {
            return $draft !== '2020-12';
        }
        if (in_array($keyword, ['if', 'then', 'else'], true)) {
            return $draft !== '06';
        }
        if (in_array($keyword, ['$defs', 'dependentSchemas', 'dependentRequired', 'unevaluatedProperties', 'unevaluatedItems', 'contentSchema'], true)) {
            return in_array($draft, ['2019-09', '2020-12'], true);
        }

        return $keyword === 'prefixItems' && $draft === '2020-12';
    }

    /**
     * PHP schema arrays need context: schema/map positions are objects, but
     * instance values (const/enum/default/examples) keep ordinary JSON shapes.
     * Empty or numeric-key literal objects must be explicit stdClass/JsonObject.
     *
     * @param  array<array-key, mixed>  $schema
     */
    private static function schemaObject(array $schema, string $draft): \stdClass
    {
        $draft = self::draft($schema['$schema'] ?? null, $draft);
        $result = new \stdClass;

        foreach ($schema as $key => $value) {
            // Keywords introduced by later drafts are ordinary extension data.
            $shape = self::schemaKeyword($key, $draft) ? $key : null;
            $result->{(string) $key} = match ($shape) {
                'properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas' => self::objectMap($value, static fn (mixed $item): \stdClass|bool => self::schemaValue($item, $draft)),
                'dependentRequired' => self::objectMap($value, self::jsonValue(...)),
                // Draft 6/7 dependencies mixes property-name lists and schemas.
                'dependencies' => self::objectMap($value, static fn (mixed $item): mixed => is_array($item) && array_is_list($item) || $item instanceof JsonArray
                    ? self::jsonValue($item)
                    : self::schemaValue($item, $draft)),
                'additionalProperties', 'unevaluatedProperties', 'propertyNames', 'contains', 'not', 'if', 'then', 'else', 'additionalItems', 'unevaluatedItems', 'contentSchema' => self::schemaValue($value, $draft),
                // Empty PHP [] in a schema position retains the existing {} shorthand.
                // An explicit JsonArray can still represent the legacy empty tuple.
                'items' => is_array($value) && $value !== [] && array_is_list($value) || $value instanceof JsonArray
                    ? self::schemaList($value, $draft)
                    : self::schemaValue($value, $draft),
                'allOf', 'anyOf', 'oneOf', 'prefixItems' => self::schemaList($value, $draft),
                default => self::jsonValue($value),
            };
        }

        return $result;
    }

    private static function schemaValue(mixed $value, string $draft): \stdClass|bool
    {
        if ($value instanceof JsonObject) {
            return self::schemaObject($value->data, $draft);
        }
        if ($value instanceof \stdClass) {
            return self::schemaObject(get_object_vars($value), $draft);
        }
        if (is_array($value) && $value !== [] && array_is_list($value)) {
            throw new \InvalidArgumentException('A schema must be an object or boolean.');
        }

        if (is_array($value)) {
            return self::schemaObject($value, $draft);
        }
        if (! is_bool($value)) {
            throw new \InvalidArgumentException('A schema must be an object or boolean.');
        }

        return $value;
    }

    /** @return list<\stdClass|bool> */
    private static function schemaList(mixed $value, string $draft): array
    {
        if ($value instanceof JsonArray) {
            $value = $value->data;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new \InvalidArgumentException('A schema list must be a JSON array.');
        }

        return array_map(static fn (mixed $item): \stdClass|bool => self::schemaValue($item, $draft), $value);
    }

    /** @param  \Closure(mixed): mixed  $normalize */
    private static function objectMap(mixed $value, \Closure $normalize): \stdClass
    {
        if ($value instanceof JsonObject) {
            $value = $value->data;
        } elseif ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (! is_array($value)) {
            throw new \InvalidArgumentException('A schema map must be a JSON object.');
        }

        $object = new \stdClass;
        foreach ($value as $name => $item) {
            $object->{(string) $name} = $normalize($item);
        }

        return $object;
    }

    /** Normalize literal JSON without interpreting its member names as keywords. */
    private static function jsonValue(mixed $value): mixed
    {
        if ($value instanceof JsonObject || $value instanceof \stdClass) {
            return self::objectMap($value, self::jsonValue(...));
        }
        if ($value instanceof JsonArray) {
            $value = $value->data;
        }
        if (is_array($value)) {
            return array_is_list($value)
                ? array_map(self::jsonValue(...), $value)
                : self::objectMap($value, self::jsonValue(...));
        }
        if ($value !== null && ! is_scalar($value)) {
            throw new \InvalidArgumentException('Schema values must be JSON values.');
        }

        return $value;
    }
}
