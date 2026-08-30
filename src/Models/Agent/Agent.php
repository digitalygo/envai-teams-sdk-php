<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Agent;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * An agent definition with id `agt_<ulid>` owned by a user with id
 * `usr_<ulid>`. `config` must not contain `max_tokens` at any nesting depth
 * (server enforced) and is preserved as a wire-faithful object.
 *
 * Timestamps stay ISO 8601 strings (wire-faithful), never parsed into
 * DateTimeImmutable.
 */
final readonly class Agent implements ArrayHydratable
{
    /**
     * @param  list<AgentTool>  $toolsEnabled
     * @param  list<AgentTool>  $externalToolsEnabled
     * @param  array<string, mixed>  $permissions
     * @param  array<string, mixed>  $config
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $id,
        public string $userId,
        public string $name,
        public ?string $description,
        public string $systemPrompt,
        public ?string $model,
        public AgentMode $mode,
        public array $toolsEnabled,
        public array $externalToolsEnabled,
        public array $permissions,
        public bool $canWriteKnowledge,
        public bool $canWriteSkills,
        public array $config,
        public string $createdAt,
        public string $updatedAt,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'Agent';

        return new self(
            id: Wire::string($data, 'id', $context),
            userId: Wire::string($data, 'user_id', $context),
            name: Wire::string($data, 'name', $context),
            description: Wire::nullableString($data, 'description', $context),
            systemPrompt: Wire::string($data, 'system_prompt', $context),
            model: Wire::nullableString($data, 'model', $context),
            mode: Wire::enum($data, 'mode', AgentMode::class, $context),
            toolsEnabled: self::agentTools($data, 'tools_enabled', $context),
            externalToolsEnabled: self::agentTools($data, 'external_tools_enabled', $context),
            permissions: Wire::objectArrayDefault($data, 'permissions', $context),
            canWriteKnowledge: Wire::boolDefault($data, 'can_write_knowledge', $context),
            canWriteSkills: Wire::boolDefault($data, 'can_write_skills', $context),
            config: Wire::objectArrayDefault($data, 'config', $context),
            createdAt: Wire::string($data, 'created_at', $context),
            updatedAt: Wire::string($data, 'updated_at', $context),
            extra: Wire::extra($data, [
                'id',
                'user_id',
                'name',
                'description',
                'system_prompt',
                'model',
                'mode',
                'tools_enabled',
                'external_tools_enabled',
                'permissions',
                'can_write_knowledge',
                'can_write_skills',
                'config',
                'created_at',
                'updated_at',
            ]),
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<AgentTool>
     */
    private static function agentTools(array $data, string $key, string $context): array
    {
        $raw = $data[$key] ?? [];

        if (! is_array($raw)) {
            throw new \InvalidArgumentException(sprintf(
                '%s payload field "%s" must be an array, got %s.',
                $context,
                $key,
                get_debug_type($raw),
            ));
        }

        $tools = [];

        foreach ($raw as $item) {
            if (! is_array($item)) {
                throw new \InvalidArgumentException(sprintf(
                    '%s payload field "%s" must be a list of tool objects, got a non-array entry.',
                    $context,
                    $key,
                ));
            }

            $tools[] = AgentTool::fromArray(Wire::stringKeyed($item, $key, $context));
        }

        return $tools;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'id' => $this->id,
            'user_id' => $this->userId,
            'name' => $this->name,
            'description' => $this->description,
            'system_prompt' => $this->systemPrompt,
            'model' => $this->model,
            'mode' => $this->mode->value,
            'tools_enabled' => array_map(static fn (AgentTool $tool): array => $tool->toArray(), $this->toolsEnabled),
            'external_tools_enabled' => array_map(static fn (AgentTool $tool): array => $tool->toArray(), $this->externalToolsEnabled),
            'permissions' => $this->permissions,
            'can_write_knowledge' => $this->canWriteKnowledge,
            'can_write_skills' => $this->canWriteSkills,
            'config' => $this->config,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ], $this->extra);
    }
}
