<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Agent;

/**
 * Payload to create an agent definition. `name`, `system_prompt` and `mode`
 * are required; `description` and `model` are optional, the four jsonb
 * fields default to empty values when omitted and `user_id` is prohibited.
 *
 * Request DTOs are plain constructors: unknown fields are rejected by the
 * server and are never serialized here.
 */
final readonly class AgentCreateRequest
{
    /**
     * @param  list<AgentTool>  $toolsEnabled
     * @param  list<AgentTool>  $externalToolsEnabled
     * @param  array<string, mixed>  $permissions
     * @param  array<string, mixed>  $config
     */
    public function __construct(
        public string $name,
        public string $systemPrompt,
        public AgentMode $mode,
        public ?string $description = null,
        public ?string $model = null,
        public array $toolsEnabled = [],
        public array $externalToolsEnabled = [],
        public array $permissions = [],
        public bool $canWriteKnowledge = false,
        public bool $canWriteSkills = false,
        public array $config = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'name' => $this->name,
            'system_prompt' => $this->systemPrompt,
            'mode' => $this->mode->value,
            'can_write_knowledge' => $this->canWriteKnowledge,
            'can_write_skills' => $this->canWriteSkills,
        ];

        if ($this->description !== null) {
            $payload['description'] = $this->description;
        }

        if ($this->model !== null) {
            $payload['model'] = $this->model;
        }

        if ($this->toolsEnabled !== []) {
            $payload['tools_enabled'] = array_map(static fn (AgentTool $tool): array => $tool->toArray(), $this->toolsEnabled);
        }

        if ($this->externalToolsEnabled !== []) {
            $payload['external_tools_enabled'] = array_map(static fn (AgentTool $tool): array => $tool->toArray(), $this->externalToolsEnabled);
        }

        if ($this->permissions !== []) {
            $payload['permissions'] = $this->permissions;
        }

        if ($this->config !== []) {
            $payload['config'] = $this->config;
        }

        return $payload;
    }
}
