<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Schema;

use Opis\JsonSchema\Parsers\SchemaParser;
use Opis\JsonSchema\Resolvers\SchemaResolver;
use Opis\JsonSchema\Schema;
use Opis\JsonSchema\SchemaLoader;
use Opis\JsonSchema\Schemas\ExceptionSchema;
use Opis\JsonSchema\Uri;

/** @internal Index schema metadata only, never inert literal/extension objects. */
final class OfflineSchemaLoader extends SchemaLoader
{
    /** @var \SplObjectStorage<object, null> */
    private \SplObjectStorage $locations;

    /** @var \SplObjectStorage<Schema, GuardedSchema> */
    private \SplObjectStorage $guards;

    /** @var \SplObjectStorage<object, array{?Uri, ?Uri, array<int|string>, string, string}> */
    private \SplObjectStorage $inertLocations;

    private ?string $documentDraft = null;

    public function __construct(SchemaParser $parser)
    {
        parent::__construct($parser, new SchemaResolver);
        $this->locations = new \SplObjectStorage;
        $this->guards = new \SplObjectStorage;
        $this->inertLocations = new \SplObjectStorage;
    }

    public function loadObjectSchema(object $data, $id = null, ?string $draft = null): Schema
    {
        if (! $data instanceof \stdClass) {
            throw new \InvalidArgumentException('A schema must be a JSON object.');
        }
        $this->declareLocations($data, $draft ?? $this->parser()->defaultDraftVersion());
        if (! $this->dataCache->offsetExists($data) && $this->inertLocations->offsetExists($data)) {
            [$base, $root, $path, $locatedDraft, $pointer] = $this->inertLocations[$data];
            // A JSON Pointer explicitly promotes this literal to a schema.
            // Retain its document location instead of treating a fragment as a root ID.
            $this->handleObject($data, null, $base, $root, $path, $locatedDraft, $pointer);
        }

        return $this->guard(parent::loadObjectSchema($data, $id, $draft));
    }

    public function loadSchemaById(Uri $uri): ?Schema
    {
        $schema = parent::loadSchemaById($uri);

        return $schema === null ? null : $this->guard($schema);
    }

    private function declareLocations(\stdClass $node, string $parentDraft): void
    {
        if ($this->locations->offsetExists($node)) {
            return;
        }
        $this->locations->offsetSet($node);
        $draft = ToolSchemaNormalizer::draft($node->{'$schema'} ?? null, $parentDraft);
        $this->documentDraft ??= $draft;
        if ($draft !== $this->documentDraft) {
            throw new \InvalidArgumentException('Mixed-dialect embedded schema resources are not supported.');
        }
        foreach (ToolSchemaDocument::children($node, $draft) as $child) {
            $this->declareLocations($child, $draft);
        }
    }

    private function guard(Schema $schema): Schema
    {
        if ($schema instanceof GuardedSchema || $schema instanceof ExceptionSchema) {
            return $schema;
        }
        if (! $this->guards->offsetExists($schema)) {
            $this->guards[$schema] = new GuardedSchema($schema);
        }

        return $this->guards[$schema];
    }

    /** @param array<int|string> $path */
    protected function handleObject(object $data, ?Uri $id, ?Uri $base, ?Uri $root, array $path, string $draft, string $pointer): void
    {
        if ($this->locations->offsetExists($data)) {
            parent::handleObject($data, $id, $base, $root, $path, $draft, $pointer);

            return;
        }
        $this->inertLocations[$data] = [$base, $root, $path, $draft, $pointer];
        // Walk containers to declared child schemas, without giving literal
        // $id/$anchor/$schema members any resource or dialect semantics.
        foreach (get_object_vars($data) as $key => $value) {
            $nextPath = [...$path, $key];
            $nextPointer = $pointer.'/'.str_replace(['~', '/'], ['~0', '~1'], (string) $key);
            if (is_object($value)) {
                $this->handleObject($value, null, $base, $root, $nextPath, $draft, $nextPointer);
            } elseif (is_array($value)) {
                $this->handleContainerArray($value, $base, $root, $nextPath, $draft, $nextPointer);
            }
        }
    }

    /** @param array<mixed> $data
     * @param  array<int|string>  $path
     */
    private function handleContainerArray(array $data, ?Uri $base, ?Uri $root, array $path, string $draft, string $pointer): void
    {
        foreach ($data as $key => $value) {
            if (is_object($value)) {
                $this->handleObject($value, null, $base, $root, [...$path, $key], $draft, $pointer.'/'.$key);
            } elseif (is_array($value)) {
                $this->handleContainerArray($value, $base, $root, [...$path, $key], $draft, $pointer.'/'.$key);
            }
        }
    }
}
