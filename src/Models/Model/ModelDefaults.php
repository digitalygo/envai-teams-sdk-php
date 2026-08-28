<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Model;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Platform default model id per role, used at runtime when no model is
 * configured for the agent.
 */
final readonly class ModelDefaults implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $orchestrator,
        public string $subagent,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'ModelDefaults';

        return new self(
            orchestrator: Wire::string($data, 'orchestrator', $context),
            subagent: Wire::string($data, 'subagent', $context),
            extra: Wire::extra($data, ['orchestrator', 'subagent']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'orchestrator' => $this->orchestrator,
            'subagent' => $this->subagent,
        ], $this->extra);
    }
}
