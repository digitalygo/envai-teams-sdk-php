<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Execution;

use Digitalygo\Envai\Models\Json\CanonicalJsonSerializer;
use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;
use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Validator;

/** Complete local JSON Schema validation without remote reference fetching. */
final class ToolArgumentsValidator
{
    /**
     * @param  array<string, mixed>  $schema
     * @return list<string>
     */
    public static function validate(JsonObject $arguments, array $schema): array
    {
        try {
            // Preserve empty objects and arrays from the verified wire tree.
            $data = json_decode(CanonicalJsonSerializer::serialize($arguments), false, 512, JSON_THROW_ON_ERROR);
            $parser = new SchemaParser(options: [
                'allowFilters' => false,
                'allowMappers' => false,
                'allowTemplates' => false,
                'allowGlobals' => false,
                'allowDefaults' => false,
                'allowSlots' => false,
                'allowKeywordValidators' => false,
                'allowPragmas' => false,
                'allowDataKeyword' => false,
            ]);
            // No registered files, URI protocols or directories: external
            // references cannot perform network or filesystem reads.
            $validator = new Validator(new SchemaLoader($parser, new SchemaResolver));
            $result = $validator->validate($data, self::schemaValue($schema));

            return $result->isValid() ? [] : ['Arguments do not satisfy the complete tool JSON Schema.'];
        } catch (\Throwable) {
            // Malformed/unresolvable schemas are fail-closed too. Do not leak
            // local paths, argument values or validator exception messages.
            return ['Arguments could not be validated against the local tool JSON Schema.'];
        }
    }

    /**
     * PHP schema arrays need context: schema/map positions are objects, but
     * instance values (const/enum/default/examples) keep ordinary JSON shapes.
     * Empty or numeric-key literal objects must be explicit stdClass/JsonObject.
     *
     * @param  array<array-key, mixed>  $schema
     */
    private static function schemaObject(array $schema): \stdClass
    {
        $result = new \stdClass;

        foreach ($schema as $key => $value) {
            $result->{(string) $key} = match ($key) {
                'properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas' => self::objectMap($value, self::schemaValue(...)),
                'dependentRequired' => self::objectMap($value, self::jsonValue(...)),
                // Draft 6/7 dependencies mixes property-name lists and schemas.
                'dependencies' => self::objectMap($value, static fn (mixed $item): mixed => is_array($item) && array_is_list($item) || $item instanceof JsonArray
                    ? self::jsonValue($item)
                    : self::schemaValue($item)),
                'additionalProperties', 'unevaluatedProperties', 'propertyNames', 'contains', 'not', 'if', 'then', 'else', 'additionalItems', 'unevaluatedItems', 'contentSchema' => self::schemaValue($value),
                // Empty PHP [] in a schema position retains the existing {} shorthand.
                // An explicit JsonArray can still represent the legacy empty tuple.
                'items' => is_array($value) && $value !== [] && array_is_list($value) || $value instanceof JsonArray
                    ? self::schemaList($value)
                    : self::schemaValue($value),
                'allOf', 'anyOf', 'oneOf', 'prefixItems' => self::schemaList($value),
                default => self::jsonValue($value),
            };
        }

        return $result;
    }

    private static function schemaValue(mixed $value): \stdClass|bool
    {
        if ($value instanceof JsonObject) {
            return self::schemaObject($value->data);
        }
        if ($value instanceof \stdClass) {
            return self::schemaObject(get_object_vars($value));
        }
        if (is_array($value) && $value !== [] && array_is_list($value)) {
            throw new \InvalidArgumentException('A schema must be an object or boolean.');
        }

        if (is_array($value)) {
            return self::schemaObject($value);
        }
        if (! is_bool($value)) {
            throw new \InvalidArgumentException('A schema must be an object or boolean.');
        }

        return $value;
    }

    /** @return list<\stdClass|bool> */
    private static function schemaList(mixed $value): array
    {
        if ($value instanceof JsonArray) {
            $value = $value->data;
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new \InvalidArgumentException('A schema list must be a JSON array.');
        }

        return array_map(self::schemaValue(...), $value);
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
