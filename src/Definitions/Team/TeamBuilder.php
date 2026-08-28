<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Team;

/**
 * Mutable fluent builder for TeamDefinition instances. The builder is
 * created through Team::make() with the immutable local key and mutated
 * through the fluent name(), description(), orchestrator() and member()
 * methods; build() freezes it into an immutable TeamDefinition.
 */
final class TeamBuilder
{
    private string $name = '';

    private ?string $description = null;

    private bool $hasDescription = false;

    private ?string $orchestratorKey = null;

    /**
     * @var list<TeamMemberDefinition>
     */
    private array $members = [];

    private function __construct(private readonly string $localKey) {}

    public static function make(string $localKey): self
    {
        return new self($localKey);
    }

    public function localKey(): string
    {
        return $this->localKey;
    }

    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    public function description(?string $description): self
    {
        $this->description = $description;
        $this->hasDescription = true;

        return $this;
    }

    /**
     * Sets the local agent key of the orchestrator.
     */
    public function orchestrator(string $localAgentKey): self
    {
        $this->orchestratorKey = $localAgentKey;

        return $this;
    }

    /**
     * Appends a subagent member in order; the role is optional.
     */
    public function member(string $localAgentKey, ?string $role = null): self
    {
        $this->members[] = new TeamMemberDefinition($localAgentKey, $role);

        return $this;
    }

    /**
     * Freezes the builder into an immutable TeamDefinition. Throws a
     * LogicException when the name or the orchestrator was not set.
     */
    public function build(): TeamDefinition
    {
        if ($this->name === '') {
            throw new \LogicException(sprintf('Team "%s" is missing its name.', $this->localKey));
        }

        if ($this->orchestratorKey === null) {
            throw new \LogicException(sprintf('Team "%s" is missing its orchestrator.', $this->localKey));
        }

        return new TeamDefinition(
            localKey: $this->localKey,
            name: $this->name,
            description: $this->description,
            orchestratorKey: $this->orchestratorKey,
            members: $this->members,
            hasDescription: $this->hasDescription,
        );
    }
}
