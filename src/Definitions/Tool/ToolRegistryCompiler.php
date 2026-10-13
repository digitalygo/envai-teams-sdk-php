<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Tool;

use Digitalygo\Envai\Definitions\Validation\DefinitionViolation;
use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Tools\Contracts\ClientTool;
use Digitalygo\Envai\Tools\Contracts\ClientToolFactory;
use Digitalygo\Envai\Tools\Schema\ToolSchemaDocument;
use Digitalygo\Envai\Tools\Schema\ToolSchemaNormalizer;

/**
 * Compiles the `envai/tools.php` registry into ToolDefinition instances.
 * The file must return an array keyed by remote tool name; each entry is
 * either a handler class name or an array with `class` and an optional
 * `timeout_seconds`. Handlers are instantiated through a ClientToolFactory
 * so that description() and schema() can be read at compile time.
 *
 * The schema is normalized: non-string keys are dropped and a missing
 * root `type` defaults to `object`. A root type that contradicts `object`
 * is a violation and the tool is excluded. The complete schema is checked
 * against its bundled draft metaschema and offline references; invalid schemas
 * become tool.invalid_schema violations. The timeout defaults to 120
 * seconds and is clamped to the 1..600 range. Reserved platform tool
 * names are deliberately not validated locally: the platform rejects
 * them.
 *
 * Per-entry content problems are reported as DefinitionViolation entries
 * instead of throwing; only a missing file or a registry that does not
 * return an array is a structural error.
 */
final class ToolRegistryCompiler
{
    private const DEFAULT_TIMEOUT_SECONDS = 120;

    private const MIN_TIMEOUT_SECONDS = 1;

    private const MAX_TIMEOUT_SECONDS = 600;

    /**
     * @return array{definitions: array<string, ToolDefinition>, violations: list<DefinitionViolation>}
     */
    public function compile(string $toolsPhpPath, ClientToolFactory $factory): array
    {
        if (! is_file($toolsPhpPath)) {
            throw new \InvalidArgumentException(sprintf('Tools registry file "%s" does not exist.', $toolsPhpPath));
        }

        $entries = (static function (string $path): mixed {
            return include $path;
        })($toolsPhpPath);

        if (! is_array($entries)) {
            throw new \InvalidArgumentException(sprintf(
                'Tools registry file "%s" must return an array of tool definitions.',
                $toolsPhpPath,
            ));
        }

        $definitions = [];
        $violations = [];

        foreach ($entries as $name => $entry) {
            if (! is_string($name) || $name === '') {
                $violations[] = new DefinitionViolation(
                    (string) $name,
                    '',
                    'tool.invalid_name',
                    'Tools registry keys must be non-empty strings, got '.get_debug_type($name).'.',
                );

                continue;
            }

            if (is_string($entry)) {
                $className = $entry;
                $timeout = self::DEFAULT_TIMEOUT_SECONDS;
            } elseif (is_array($entry)) {
                $className = $entry['class'] ?? null;
                $timeout = $entry['timeout_seconds'] ?? self::DEFAULT_TIMEOUT_SECONDS;

                if (! is_int($timeout)) {
                    $violations[] = new DefinitionViolation(
                        $name,
                        'timeout_seconds',
                        'tool.invalid_timeout',
                        sprintf('Tool "%s" timeout_seconds must be an integer, got %s.', $name, get_debug_type($timeout)),
                    );

                    continue;
                }
            } else {
                $violations[] = new DefinitionViolation(
                    $name,
                    '',
                    'tool.invalid_entry',
                    sprintf('Tool "%s" must be a handler class name or an array, got %s.', $name, get_debug_type($entry)),
                );

                continue;
            }

            if (! is_string($className) || $className === '') {
                $violations[] = new DefinitionViolation(
                    $name,
                    'class',
                    'tool.missing_class',
                    sprintf('Tool "%s" is missing its handler class.', $name),
                );

                continue;
            }

            if (! class_exists($className) || ! is_subclass_of($className, ClientTool::class)) {
                $violations[] = new DefinitionViolation(
                    $name,
                    'class',
                    'tool.invalid_class',
                    sprintf('Tool "%s" class "%s" must exist and implement %s.', $name, $className, ClientTool::class),
                );

                continue;
            }

            try {
                $handler = $factory->create($className);
                $description = $handler->description();
                $schema = $handler->schema();
            } catch (\Throwable $exception) {
                $violations[] = new DefinitionViolation(
                    $name,
                    'class',
                    'tool.invalid_handler',
                    sprintf('Tool "%s" handler "%s" cannot be inspected: %s', $name, $className, $exception->getMessage()),
                );

                continue;
            }

            $normalizedSchema = self::normalizeSchema($schema);

            if ($normalizedSchema === null) {
                $violations[] = new DefinitionViolation(
                    $name,
                    'schema.type',
                    'tool.schema_root_not_object',
                    sprintf('Tool "%s" schema root type must be "object".', $name),
                );

                continue;
            }

            try {
                $document = ToolSchemaDocument::prepare($normalizedSchema);
                $parameters = ToolSchemaNormalizer::objectPayload($document->schema);
            } catch (\Throwable) {
                $violations[] = new DefinitionViolation($name, 'schema', 'tool.invalid_schema', sprintf('Tool "%s" schema must be valid and resolvable offline.', $name));

                continue;
            }

            $definitions[$name] = new ToolDefinition(
                localKey: $name,
                handlerClass: $className,
                description: $description,
                parameters: new JsonObject($parameters),
                timeoutSeconds: self::clampTimeout($timeout),
            );
        }

        return ['definitions' => $definitions, 'violations' => $violations];
    }

    /**
     * Keeps string keys and defaults a missing root `type` to `object`.
     * Returns null when the root type contradicts `object`.
     *
     * @param  array<mixed, mixed>  $schema
     * @return array<string, mixed>|null
     */
    private static function normalizeSchema(array $schema): ?array
    {
        $normalized = [];

        foreach ($schema as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        if (array_key_exists('type', $normalized)) {
            return $normalized['type'] === 'object' ? $normalized : null;
        }

        $normalized['type'] = 'object';

        return $normalized;
    }

    private static function clampTimeout(int $timeout): int
    {
        return max(self::MIN_TIMEOUT_SECONDS, min(self::MAX_TIMEOUT_SECONDS, $timeout));
    }
}
