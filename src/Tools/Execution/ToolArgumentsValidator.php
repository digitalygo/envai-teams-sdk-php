<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Execution;

use Digitalygo\Envai\Models\Json\CanonicalJsonSerializer;
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
            $result = $validator->validate($data, self::schemaObject($schema));

            return $result->isValid() ? [] : ['Arguments do not satisfy the complete tool JSON Schema.'];
        } catch (\Throwable) {
            // Malformed/unresolvable schemas are fail-closed too. Do not leak
            // local paths, argument values or validator exception messages.
            return ['Arguments could not be validated against the local tool JSON Schema.'];
        }
    }

    /**
     * PHP authors express schema maps as arrays; schema positions must be
     * JSON objects even when an empty PHP array is used for properties/{}.
     *
     * @param  array<array-key, mixed>  $schema
     */
    private static function schemaObject(array $schema): \stdClass
    {
        $result = new \stdClass;

        foreach ($schema as $key => $value) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException('Schema keys must be strings.');
            }

            if (is_array($value)) {
                if (in_array($key, ['properties', 'patternProperties', '$defs', 'definitions', 'dependentSchemas'], true)) {
                    $map = new \stdClass;
                    foreach ($value as $name => $subschema) {
                        $map->{(string) $name} = is_array($subschema) ? self::schemaObject($subschema) : $subschema;
                    }
                    $value = $map;
                } elseif (in_array($key, ['additionalProperties', 'unevaluatedProperties', 'propertyNames', 'contains', 'not', 'if', 'then', 'else', 'additionalItems', 'unevaluatedItems', 'contentSchema'], true)) {
                    $value = self::schemaObject($value);
                } elseif ($key === 'items' && ($value === [] || ! array_is_list($value))) {
                    $value = self::schemaObject($value);
                } elseif (in_array($key, ['allOf', 'anyOf', 'oneOf', 'prefixItems', 'items'], true)) {
                    $value = array_map(static fn (mixed $item): mixed => is_array($item) ? self::schemaObject($item) : $item, $value);
                }
            }

            $result->{$key} = $value;
        }

        return $result;
    }
}
