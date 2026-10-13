<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Tool;

use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Tools\Schema\ToolSchemaNormalizer;

/**
 * Payload to register a client tool in the caller's user registry.
 *
 * `name` and `parameters` are required; `kind` defaults to `client`,
 * `description` is optional and `timeout_seconds` defaults to 120. Reserved
 * platform tool names and unknown fields are the server's business: this DTO
 * stays dumb and never serializes anything but its own known fields.
 */
final readonly class UserToolCreateRequest
{
    /**
     * @param  array<string, mixed>  $parameters  JSON Schema describing the tool arguments.
     */
    public function __construct(
        public string $name,
        public array $parameters,
        public string $kind = 'client',
        public ?string $description = null,
        public int $timeoutSeconds = 120,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $parameters = ToolSchemaNormalizer::forPayload($this->parameters);
        $payload = [
            'name' => $this->name,
            'kind' => $this->kind,
            'parameters' => array_is_list($parameters) ? new JsonObject($parameters) : $parameters,
            'timeout_seconds' => $this->timeoutSeconds,
        ];

        if ($this->description !== null) {
            $payload['description'] = $this->description;
        }

        return $payload;
    }
}
