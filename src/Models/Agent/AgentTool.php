<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Agent;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * Tool reference of `tools_enabled` or `external_tools_enabled`. `name` is
 * required; additional keys are allowed by the schema and preserved as-is
 * into $extra, so a future tool option survives hydration and is re-emitted
 * by toArray() when the entry is used inside a request payload.
 */
final readonly class AgentTool implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Additional tool keys preserved for forward compatibility.
     */
    public function __construct(
        public string $name,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'AgentTool';

        return new self(
            name: Wire::string($data, 'name', $context),
            extra: Wire::extra($data, ['name']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(['name' => $this->name], $this->extra);
    }
}
