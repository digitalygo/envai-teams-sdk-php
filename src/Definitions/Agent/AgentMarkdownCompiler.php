<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Agent;

use Digitalygo\Envai\Models\Json\JsonObject;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Compiles agent markdown files with YAML frontmatter into immutable
 * AgentDefinition instances. The frontmatter is the block delimited by
 * the first two `---` lines, the body is the markdown that follows
 * (newlines normalized to \n, otherwise untouched). The local key is the
 * file name without the `.md` extension.
 *
 * Structural file problems (unreadable file, missing delimiters,
 * unparseable YAML, frontmatter that is not a mapping, values that do
 * not fit the typed definition) throw an InvalidArgumentException; the
 * DefinitionCompiler converts those into DefinitionViolation entries. In
 * particular, the frontmatter format must be 1 and the mode must be
 * `primary` or `subagent`, otherwise the file cannot be represented as a
 * definition and a violation is reported.
 */
final class AgentMarkdownCompiler
{
    private const KNOWN_KEYS = [
        'format',
        'name',
        'description',
        'mode',
        'model',
        'tools',
        'permissions',
        'can_write_knowledge',
        'can_write_skills',
        'config',
    ];

    public function compileFile(string $path): AgentDefinition
    {
        $content = $this->readFile($path);
        $normalized = str_replace(["\r\n", "\r"], "\n", $content);
        [$frontmatterYaml, $body] = $this->extractFrontmatter($path, $normalized);
        $frontmatter = $this->parseFrontmatter($path, $frontmatterYaml);
        $localKey = pathinfo($path, PATHINFO_FILENAME);

        return $this->buildDefinition($localKey, $frontmatter, $body);
    }

    private function readFile(string $path): string
    {
        if (! is_file($path)) {
            throw new \InvalidArgumentException(sprintf('Agent file "%s" does not exist.', $path));
        }

        $content = @file_get_contents($path);

        if ($content === false) {
            throw new \InvalidArgumentException(sprintf('Agent file "%s" cannot be read.', $path));
        }

        return $content;
    }

    /**
     * Splits a file into its YAML frontmatter and its markdown body. The
     * body is returned as-is here; the final system prompt trims its
     * leading and trailing whitespace to match the platform's
     * normalization (with newlines already normalized to \n).
     *
     * @return array{0: string, 1: string}
     */
    private function extractFrontmatter(string $path, string $content): array
    {
        if (! str_starts_with($content, "---\n")) {
            throw new \InvalidArgumentException(sprintf(
                'Agent file "%s" has no frontmatter; the file must start with a --- delimiter line.',
                $path,
            ));
        }

        $end = strpos($content, "\n---\n", 4);

        if ($end === false) {
            throw new \InvalidArgumentException(sprintf(
                'Agent file "%s" has unterminated frontmatter; a closing --- delimiter line is missing.',
                $path,
            ));
        }

        return [
            substr($content, 4, $end - 4),
            substr($content, $end + 5),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseFrontmatter(string $path, string $yaml): array
    {
        try {
            $parsed = Yaml::parse($yaml);
        } catch (ParseException $exception) {
            throw new \InvalidArgumentException(sprintf(
                'Agent file "%s" has invalid YAML frontmatter: %s',
                $path,
                $exception->getMessage(),
            ), 0, $exception);
        }

        if ($parsed === null) {
            return [];
        }

        if (! is_array($parsed) || array_is_list($parsed)) {
            throw new \InvalidArgumentException(sprintf(
                'Agent file "%s" frontmatter must be a YAML mapping, got %s.',
                $path,
                get_debug_type($parsed),
            ));
        }

        $frontmatter = [];

        foreach ($parsed as $key => $value) {
            if (is_string($key)) {
                $frontmatter[$key] = $value;
            }
        }

        return $frontmatter;
    }

    /**
     * @param  array<string, mixed>  $frontmatter
     */
    private function buildDefinition(string $localKey, array $frontmatter, string $body): AgentDefinition
    {
        if (($frontmatter['format'] ?? null) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Agent "%s" frontmatter format must be 1, got %s.',
                $localKey,
                get_debug_type($frontmatter['format'] ?? null),
            ));
        }

        $mode = $this->parseMode($localKey, $frontmatter['mode'] ?? null);
        $name = $this->stringField($localKey, 'name', $frontmatter['name'] ?? $localKey);
        $description = $this->nullableStringField($localKey, 'description', $frontmatter['description'] ?? null);
        $model = $this->nullableStringField($localKey, 'model', $frontmatter['model'] ?? null);

        [$serverTools, $clientTools] = $this->parseTools($localKey, $frontmatter['tools'] ?? []);
        $permissions = $this->objectField($localKey, 'permissions', $frontmatter['permissions'] ?? []);
        $config = $this->objectField($localKey, 'config', $frontmatter['config'] ?? []);
        $canWriteKnowledge = $this->boolField($localKey, 'can_write_knowledge', $frontmatter['can_write_knowledge'] ?? false);
        $canWriteSkills = $this->boolField($localKey, 'can_write_skills', $frontmatter['can_write_skills'] ?? false);

        $extra = array_diff_key($frontmatter, array_flip(self::KNOWN_KEYS));

        return new AgentDefinition(
            localKey: $localKey,
            name: $name,
            description: $description,
            systemPrompt: trim($body),
            mode: $mode,
            model: $model,
            serverTools: $serverTools,
            clientTools: $clientTools,
            permissions: new JsonObject($permissions),
            canWriteKnowledge: $canWriteKnowledge,
            canWriteSkills: $canWriteSkills,
            config: new JsonObject($config),
            extra: $extra,
            presentKeys: $this->presentKeys($frontmatter),
        );
    }

    private function parseMode(string $localKey, mixed $value): AgentMode
    {
        $mode = is_string($value) ? AgentMode::tryFrom($value) : null;

        if ($mode === null) {
            throw new \InvalidArgumentException(sprintf(
                'Agent "%s" mode must be "primary" or "subagent", got %s.',
                $localKey,
                get_debug_type($value),
            ));
        }

        return $mode;
    }

    private function stringField(string $localKey, string $field, mixed $value): string
    {
        if (! is_string($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Agent "%s" frontmatter field "%s" must be a string, got %s.',
                $localKey,
                $field,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    private function nullableStringField(string $localKey, string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return $this->stringField($localKey, $field, $value);
    }

    /**
     * @return array{0: list<string>, 1: list<string>}
     */
    private function parseTools(string $localKey, mixed $tools): array
    {
        if (! is_array($tools) || ($tools !== [] && array_is_list($tools))) {
            throw new \InvalidArgumentException(sprintf(
                'Agent "%s" frontmatter field "tools" must be a mapping, got %s.',
                $localKey,
                get_debug_type($tools),
            ));
        }

        return [
            $this->stringList($localKey, 'tools.server', $tools['server'] ?? null),
            $this->stringList($localKey, 'tools.client', $tools['client'] ?? null),
        ];
    }

    /**
     * @return list<string>
     */
    private function stringList(string $localKey, string $field, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ! array_is_list($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Agent "%s" frontmatter field "%s" must be a list of strings, got %s.',
                $localKey,
                $field,
                get_debug_type($value),
            ));
        }

        foreach ($value as $item) {
            if (! is_string($item)) {
                throw new \InvalidArgumentException(sprintf(
                    'Agent "%s" frontmatter field "%s" must be a list of strings, got a non-string entry.',
                    $localKey,
                    $field,
                ));
            }
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function objectField(string $localKey, string $field, mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new \InvalidArgumentException(sprintf(
                'Agent "%s" frontmatter field "%s" must be a mapping, got %s.',
                $localKey,
                $field,
                get_debug_type($value),
            ));
        }

        $object = [];

        foreach ($value as $key => $item) {
            if (is_string($key)) {
                $object[$key] = $item;
            }
        }

        return $object;
    }

    private function boolField(string $localKey, string $field, mixed $value): bool
    {
        if (! is_bool($value)) {
            throw new \InvalidArgumentException(sprintf(
                'Agent "%s" frontmatter field "%s" must be a boolean, got %s.',
                $localKey,
                $field,
                get_debug_type($value),
            ));
        }

        return $value;
    }

    /**
     * Collects the frontmatter keys that were explicitly present, adding
     * the granular `tools.server` and `tools.client` entries so the
     * definition can distinguish an empty tool list from an absent one.
     *
     * @param  array<string, mixed>  $frontmatter
     * @return list<string>
     */
    private function presentKeys(array $frontmatter): array
    {
        $keys = [];

        foreach ($frontmatter as $key => $_value) {
            $keys[] = $key;
        }

        $tools = $frontmatter['tools'] ?? null;

        if (is_array($tools)) {
            if (array_key_exists('server', $tools)) {
                $keys[] = 'tools.server';
            }

            if (array_key_exists('client', $tools)) {
                $keys[] = 'tools.client';
            }
        }

        return $keys;
    }
}
