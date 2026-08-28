<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Agent;

use Digitalygo\Envai\Models\Common\OptionalField;

/**
 * Payload to update an agent definition. Any subset of the creation fields is
 * accepted and at least one updatable field is required; `user_id` is
 * prohibited.
 *
 * `description` and `model` distinguish an omitted field from an explicit
 * null (which clears the value), so every field is wrapped in an
 * OptionalField and toArray() emits only present values. Use the static
 * factories, which wrap the value and mark the other fields absent.
 */
final readonly class AgentUpdateRequest
{
    public function __construct(
        public OptionalField $name,
        public OptionalField $description,
        public OptionalField $systemPrompt,
        public OptionalField $model,
        public OptionalField $mode,
        public OptionalField $toolsEnabled,
        public OptionalField $externalToolsEnabled,
        public OptionalField $permissions,
        public OptionalField $canWriteKnowledge,
        public OptionalField $canWriteSkills,
        public OptionalField $config,
    ) {}

    public static function name(string $name): self
    {
        return self::fromFields(['name' => OptionalField::of($name)]);
    }

    public static function description(?string $description): self
    {
        return self::fromFields(['description' => OptionalField::of($description)]);
    }

    public static function systemPrompt(string $systemPrompt): self
    {
        return self::fromFields(['systemPrompt' => OptionalField::of($systemPrompt)]);
    }

    public static function model(?string $model): self
    {
        return self::fromFields(['model' => OptionalField::of($model)]);
    }

    public static function mode(AgentMode $mode): self
    {
        return self::fromFields(['mode' => OptionalField::of($mode)]);
    }

    /**
     * @param  list<AgentTool>  $toolsEnabled
     */
    public static function toolsEnabled(array $toolsEnabled): self
    {
        return self::fromFields(['toolsEnabled' => OptionalField::of($toolsEnabled)]);
    }

    /**
     * @param  list<AgentTool>  $externalToolsEnabled
     */
    public static function externalToolsEnabled(array $externalToolsEnabled): self
    {
        return self::fromFields(['externalToolsEnabled' => OptionalField::of($externalToolsEnabled)]);
    }

    /**
     * @param  array<string, mixed>  $permissions
     */
    public static function permissions(array $permissions): self
    {
        return self::fromFields(['permissions' => OptionalField::of($permissions)]);
    }

    public static function canWriteKnowledge(bool $canWriteKnowledge): self
    {
        return self::fromFields(['canWriteKnowledge' => OptionalField::of($canWriteKnowledge)]);
    }

    public static function canWriteSkills(bool $canWriteSkills): self
    {
        return self::fromFields(['canWriteSkills' => OptionalField::of($canWriteSkills)]);
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function config(array $config): self
    {
        return self::fromFields(['config' => OptionalField::of($config)]);
    }

    /**
     * @param  array<string, OptionalField>  $fields
     */
    private static function fromFields(array $fields): self
    {
        return new self(
            name: $fields['name'] ?? OptionalField::absent(),
            description: $fields['description'] ?? OptionalField::absent(),
            systemPrompt: $fields['systemPrompt'] ?? OptionalField::absent(),
            model: $fields['model'] ?? OptionalField::absent(),
            mode: $fields['mode'] ?? OptionalField::absent(),
            toolsEnabled: $fields['toolsEnabled'] ?? OptionalField::absent(),
            externalToolsEnabled: $fields['externalToolsEnabled'] ?? OptionalField::absent(),
            permissions: $fields['permissions'] ?? OptionalField::absent(),
            canWriteKnowledge: $fields['canWriteKnowledge'] ?? OptionalField::absent(),
            canWriteSkills: $fields['canWriteSkills'] ?? OptionalField::absent(),
            config: $fields['config'] ?? OptionalField::absent(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];

        $this->put($payload, 'name', $this->name);
        $this->put($payload, 'description', $this->description);
        $this->put($payload, 'system_prompt', $this->systemPrompt);
        $this->put($payload, 'model', $this->model);

        $mode = $this->enumValue($this->mode, AgentMode::class, 'mode');

        if ($mode !== null) {
            $payload['mode'] = $mode->value;
        }

        $tools = $this->agentToolList($this->toolsEnabled, 'tools_enabled');

        if ($tools !== null) {
            $payload['tools_enabled'] = array_map(static fn (AgentTool $tool): array => $tool->toArray(), $tools);
        }

        $externalTools = $this->agentToolList($this->externalToolsEnabled, 'external_tools_enabled');

        if ($externalTools !== null) {
            $payload['external_tools_enabled'] = array_map(static fn (AgentTool $tool): array => $tool->toArray(), $externalTools);
        }

        $this->put($payload, 'permissions', $this->permissions);
        $this->put($payload, 'can_write_knowledge', $this->canWriteKnowledge);
        $this->put($payload, 'can_write_skills', $this->canWriteSkills);
        $this->put($payload, 'config', $this->config);

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function put(array &$payload, string $key, OptionalField $field): void
    {
        if ($field->isPresent()) {
            $payload[$key] = $field->value();
        }
    }

    /**
     * @template E of \BackedEnum
     *
     * @param  class-string<E>  $enumClass
     * @return E|null
     */
    private function enumValue(OptionalField $field, string $enumClass, string $fieldName): ?\BackedEnum
    {
        if (! $field->isPresent()) {
            return null;
        }

        $value = $field->value();

        if (! $value instanceof $enumClass) {
            throw new \LogicException(sprintf(
                'AgentUpdateRequest field "%s" must hold a %s instance.',
                $fieldName,
                $enumClass,
            ));
        }

        return $value;
    }

    /**
     * @return list<AgentTool>|null
     */
    private function agentToolList(OptionalField $field, string $fieldName): ?array
    {
        if (! $field->isPresent()) {
            return null;
        }

        $value = $field->value();

        if (! is_array($value)) {
            throw new \LogicException(sprintf('AgentUpdateRequest field "%s" must hold a list of AgentTool instances.', $fieldName));
        }

        $tools = [];

        foreach ($value as $item) {
            if (! $item instanceof AgentTool) {
                throw new \LogicException(sprintf('AgentUpdateRequest field "%s" must hold a list of AgentTool instances.', $fieldName));
            }

            $tools[] = $item;
        }

        return $tools;
    }
}
