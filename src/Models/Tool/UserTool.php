<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Tool;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * A user-scoped client tool with id `twl_<ulid>`. `kind` is always `client`
 * and is kept as a plain string for forward compatibility. `parameters` is
 * the JSON Schema shown to the LLM and is preserved as-is, unknown nested
 * keys included. Timestamps stay ISO 8601 strings (wire-faithful).
 */
final readonly class UserTool implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $parameters  JSON Schema describing the tool arguments.
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $kind,
        public ?string $description,
        public array $parameters,
        public int $timeoutSeconds,
        public string $createdAt,
        public string $updatedAt,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        $context = 'UserTool';

        return new self(
            id: Wire::string($data, 'id', $context),
            name: Wire::string($data, 'name', $context),
            kind: Wire::string($data, 'kind', $context),
            description: Wire::nullableString($data, 'description', $context),
            parameters: Wire::objectArray($data, 'parameters', $context),
            timeoutSeconds: Wire::int($data, 'timeout_seconds', $context),
            createdAt: Wire::string($data, 'created_at', $context),
            updatedAt: Wire::string($data, 'updated_at', $context),
            extra: Wire::extra($data, ['id', 'name', 'kind', 'description', 'parameters', 'timeout_seconds', 'created_at', 'updated_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'id' => $this->id,
            'name' => $this->name,
            'kind' => $this->kind,
            'parameters' => $this->parameters,
            'timeout_seconds' => $this->timeoutSeconds,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];

        if ($this->description !== null) {
            $payload['description'] = $this->description;
        }

        return array_merge($payload, $this->extra);
    }
}
