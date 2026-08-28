<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Agent;

use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * Immutable local definition of an agent, compiled from a markdown file
 * with YAML frontmatter in the `envai/agents` directory. The local key
 * is the file name without the `.md` extension and never changes; the
 * frontmatter `name` is the mutable remote name.
 *
 * The optional fields carry an explicit presence track (presentKeys):
 * when present keys are supplied, a field is emitted by toRemotePayload()
 * if and only if its key is listed, which keeps null, absent and empty
 * values distinct in the normalized payload. Without explicit presence
 * information, optional fields are considered present when their value is
 * non-null or non-empty.
 */
final readonly class AgentDefinition
{
    /**
     * @param  list<string>  $serverTools  Names of platform tools (tools.server).
     * @param  list<string>  $clientTools  Names of client tools (tools.client).
     * @param  array<string, mixed>  $extra  Unknown frontmatter fields preserved as-is.
     * @param  list<string>  $presentKeys  Frontmatter keys explicitly present, including the granular `tools.server` and `tools.client` entries.
     */
    public function __construct(
        public string $localKey,
        public string $name,
        public ?string $description,
        public string $systemPrompt,
        public AgentMode $mode,
        public ?string $model = null,
        public array $serverTools = [],
        public array $clientTools = [],
        public JsonObject $permissions = new JsonObject([]),
        public bool $canWriteKnowledge = false,
        public bool $canWriteSkills = false,
        public JsonObject $config = new JsonObject([]),
        public array $extra = [],
        private array $presentKeys = [],
    ) {}

    /**
     * Builds the AgentCreateRequest-shaped remote payload. The markdown
     * body becomes `system_prompt`, `tools.server` becomes `tools_enabled`
     * and `tools.client` becomes `external_tools_enabled`, both as lists
     * of AgentTool objects ({name: ...}) - never as flat string arrays.
     *
     * Absent optional fields omit their key, explicitly null fields are
     * emitted as null and empty lists or objects are kept, so the payload
     * preserves the null/absent/empty distinctions required by the
     * canonical manifest hash.
     *
     * @param  array<string, mixed>  $catalogContext  Reserved for future catalog-driven payload expansion; currently does not affect the output.
     * @return array<string, mixed>
     */
    public function toRemotePayload(array $catalogContext = []): array
    {
        $payload = [
            'name' => $this->name,
            'system_prompt' => $this->systemPrompt,
            'mode' => $this->mode->value,
            'can_write_knowledge' => $this->canWriteKnowledge,
            'can_write_skills' => $this->canWriteSkills,
        ];

        if ($this->hasFrontmatter('description')) {
            $payload['description'] = $this->description;
        }

        if ($this->hasFrontmatter('model')) {
            $payload['model'] = $this->model;
        }

        if ($this->hasFrontmatter('tools.server')) {
            $payload['tools_enabled'] = array_map(
                static fn (string $name): array => ['name' => $name],
                $this->serverTools,
            );
        }

        if ($this->hasFrontmatter('tools.client')) {
            $payload['external_tools_enabled'] = array_map(
                static fn (string $name): array => ['name' => $name],
                $this->clientTools,
            );
        }

        if ($this->hasFrontmatter('permissions')) {
            $payload['permissions'] = $this->permissions->toArray();
        }

        if ($this->hasFrontmatter('config')) {
            $payload['config'] = $this->config->toArray();
        }

        return $payload;
    }

    /**
     * Whether the given frontmatter key was explicitly present. Without
     * explicit presence information (presentKeys), `name` is always
     * considered present and the optional fields are considered present
     * when their value is non-null or non-empty.
     */
    public function hasFrontmatter(string $key): bool
    {
        if ($this->presentKeys !== []) {
            return in_array($key, $this->presentKeys, true);
        }

        return match ($key) {
            'name' => true,
            'description' => $this->description !== null,
            'model' => $this->model !== null,
            'tools.server' => $this->serverTools !== [],
            'tools.client' => $this->clientTools !== [],
            'permissions' => ! $this->permissions->isEmpty(),
            'config' => ! $this->config->isEmpty(),
            default => false,
        };
    }
}
