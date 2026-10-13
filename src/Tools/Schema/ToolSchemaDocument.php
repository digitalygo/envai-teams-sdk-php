<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Schema;

use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Schemas\ExceptionSchema;
use Opis\JsonSchema\Uri;
use Opis\JsonSchema\Validator;

/** Validates the schema itself, independently of which instance branches run. */
final class ToolSchemaDocument
{
    private static ?Validator $metas = null;

    private const METASCHEMAS = [
        '06' => 'http://json-schema.org/draft-06/schema#',
        '07' => 'http://json-schema.org/draft-07/schema#',
        '2019-09' => 'https://json-schema.org/draft/2019-09/schema',
        '2020-12' => 'https://json-schema.org/draft/2020-12/schema',
    ];

    /** @param array<array-key, mixed> $schema */
    public static function prepare(array $schema): self
    {
        $document = new self(ToolSchemaNormalizer::normalize($schema));
        $document->check();

        return $document;
    }

    public readonly SchemaLoader $loader;

    private function __construct(public readonly \stdClass $schema)
    {
        // No protocol, directory, file or remote schema registrations.
        $this->loader = new OfflineSchemaLoader(self::parser());
    }

    private static function parser(): SchemaParser
    {
        return new SchemaParser(options: [
            'allowFilters' => false, 'allowMappers' => false,
            'allowTemplates' => false, 'allowGlobals' => false,
            'allowDefaults' => false, 'allowSlots' => false,
            'allowKeywordValidators' => false, 'allowPragmas' => false,
            'allowDataKeyword' => false, 'allowRelativeJsonPointerInRef' => false,
            'allowExclusiveMinMaxAsBool' => false,
            'keepAdditionalItemsKeyword' => false,
        ]);
    }

    private static function metaValidator(): Validator
    {
        if (self::$metas !== null) {
            return self::$metas;
        }
        $resolver = new SchemaResolver;
        $root = dirname(__DIR__, 3).'/resources/json-schema/';
        $contents = file_get_contents($root.'manifest.json');
        if ($contents === false) {
            throw new \RuntimeException('Bundled metaschema manifest is unavailable.');
        }
        $manifest = json_decode($contents, false, 512, JSON_THROW_ON_ERROR);
        if (! $manifest instanceof \stdClass || ! ($manifest->schemas ?? null) instanceof \stdClass) {
            throw new \RuntimeException('Bundled metaschema manifest is invalid.');
        }
        foreach (get_object_vars($manifest->schemas) as $id => $entry) {
            if (! is_string($id) || ! $entry instanceof \stdClass || ! is_string($entry->path ?? null) || ! is_string($entry->sha256 ?? null)) {
                throw new \RuntimeException('Bundled metaschema entry is invalid.');
            }
            $json = file_get_contents($root.$entry->path);
            if ($json === false || hash('sha256', $json) !== $entry->sha256 || ! $resolver->registerRaw($json, $id)) {
                throw new \RuntimeException('Bundled metaschema bytes are invalid.');
            }
        }

        return self::$metas = new Validator(new SchemaLoader(self::parser(), $resolver));
    }

    private function check(): void
    {
        $metas = self::metaValidator();
        $queue = [[$this->schema, '2020-12']];
        $seen = new \SplObjectStorage;
        $references = [];
        $dynamicAnchors = [];
        $index = 0;
        do {
            while (isset($queue[$index])) {
                [$node, $parentDraft] = $queue[$index++];
                if ($seen->contains($node)) {
                    continue;
                }
                $seen->attach($node);
                $draft = ToolSchemaNormalizer::draft($node->{'$schema'} ?? null, $parentDraft);
                if (! $metas->validate($node, self::METASCHEMAS[$draft])->isValid()) {
                    throw new \InvalidArgumentException('The tool schema does not satisfy its draft metaschema.');
                }
                $compiled = $this->loader->loadObjectSchema($node, null, $draft);
                if ($compiled instanceof ExceptionSchema) {
                    throw new \InvalidArgumentException('The tool schema cannot be compiled.');
                }
                $base = $compiled->info()->idBaseRoot();
                if ($base === null) {
                    throw new \InvalidArgumentException('The schema has no local base URI.');
                }
                if ($draft === '2020-12' && is_string($node->{'$dynamicAnchor'} ?? null)) {
                    $dynamicAnchors[(string) Uri::merge('#'.$node->{'$dynamicAnchor'}, $base, true)] = $node;
                }
                foreach (self::children($node, $draft) as $child) {
                    $queue[] = [$child, $draft];
                }
                $keywords = ['$ref'];
                if ($draft === '2019-09') {
                    $keywords[] = '$recursiveRef';
                } elseif ($draft === '2020-12') {
                    $keywords[] = '$dynamicRef';
                    $keywords[] = '$recursiveRef';
                }
                foreach ($keywords as $keyword) {
                    if (isset($node->{$keyword})) {
                        if (! is_string($node->{$keyword})) {
                            throw new \InvalidArgumentException('Schema references must be strings.');
                        }
                        $uri = Uri::merge($node->{$keyword}, $base, true);
                        if ($uri === null) {
                            throw new \InvalidArgumentException('The tool schema contains an invalid reference.');
                        }
                        $references[] = [$uri, $draft];
                    }
                }
                // First collect all declared schemas/anchors, then discover any
                // additional schema targets living under extension keywords.
            }
            $unresolved = [];
            foreach ($references as [$uri, $referenceDraft]) {
                $target = $dynamicAnchors[(string) $uri] ?? null;
                if ($target === null) {
                    $resolved = $this->loader->loadSchemaById($uri);
                    if ($resolved === null) {
                        // A later pointer may promote a literal schema resource.
                        // Retry only while newly discovered targets make progress.
                        $unresolved[] = [$uri, $referenceDraft];

                        continue;
                    }
                    if ($resolved instanceof ExceptionSchema) {
                        throw new \InvalidArgumentException('The tool schema reference cannot be resolved locally.');
                    }
                    $target = $resolved->info()->data();
                }
                if ($target instanceof \stdClass && ! $seen->contains($target)) {
                    $queue[] = [$target, $referenceDraft];
                }
            }
            if ($unresolved !== [] && ! isset($queue[$index])) {
                throw new \InvalidArgumentException('The tool schema reference cannot be resolved locally.');
            }
            $references = $unresolved;
        } while (isset($queue[$index]));
    }

    /**
     * Traversal identifies schema positions only; validity is checked by the
     * official recursive metaschemas, not this keyword inventory. Literal and
     * unknown-extension values are inert unless a reference targets them.
     *
     * @return list<\stdClass>
     */
    public static function children(\stdClass $schema, string $draft): array
    {
        $children = [];
        $maps = ['properties', 'patternProperties', 'definitions'];
        $singles = ['additionalProperties', 'propertyNames', 'contains', 'not'];
        $lists = ['allOf', 'anyOf', 'oneOf'];
        if ($draft !== '2020-12') {
            $singles[] = 'additionalItems';
        }
        if ($draft !== '06') {
            $singles = [...$singles, 'if', 'then', 'else'];
        }
        if (in_array($draft, ['2019-09', '2020-12'], true)) {
            $maps = [...$maps, '$defs', 'dependentSchemas'];
            $singles = [...$singles, 'unevaluatedProperties', 'unevaluatedItems', 'contentSchema'];
        }
        if ($draft === '2020-12') {
            $lists[] = 'prefixItems';
        }
        foreach ($maps as $key) {
            foreach (self::mapValues($schema->{$key} ?? new \stdClass) as $child) {
                if ($child instanceof \stdClass) {
                    $children[] = $child;
                }
            }
        }
        foreach (self::mapValues($schema->dependencies ?? new \stdClass) as $child) {
            if ($child instanceof \stdClass) {
                $children[] = $child;
            }
        }
        foreach ([...$singles, 'items'] as $key) {
            $value = $schema->{$key} ?? null;
            if ($value instanceof \stdClass) {
                $children[] = $value;
            } elseif ($key === 'items' && is_array($value)) {
                foreach ($value as $child) {
                    if ($child instanceof \stdClass) {
                        $children[] = $child;
                    }
                }
            }
        }
        foreach ($lists as $key) {
            $values = $schema->{$key} ?? [];
            if (! is_array($values)) {
                throw new \InvalidArgumentException('Schema list must be an array.');
            }
            foreach ($values as $child) {
                if ($child instanceof \stdClass) {
                    $children[] = $child;
                }
            }
        }

        return $children;
    }

    /** @return array<array-key, mixed> */
    private static function mapValues(mixed $value): array
    {
        if (! $value instanceof \stdClass) {
            throw new \InvalidArgumentException('Schema map must be an object.');
        }

        return get_object_vars($value);
    }
}
