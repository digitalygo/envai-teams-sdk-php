<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Definitions\Tool;

use Digitalygo\Envai\Models\Json\JsonObject;
use Digitalygo\Envai\Tools\Contracts\ClientTool;

/**
 * Immutable local definition of a client tool, compiled from the
 * `envai/tools.php` registry. The local key is the registry key, which
 * is also the remote Envai tool name; the handler class provides the
 * description and the normalized argument schema.
 */
final readonly class ToolDefinition
{
    /**
     * @param  class-string<ClientTool>  $handlerClass
     */
    public function __construct(
        public string $localKey,
        public string $handlerClass,
        public string $description,
        public JsonObject $parameters,
        public int $timeoutSeconds,
    ) {}

    /**
     * Builds the UserToolCreateRequest-shaped remote payload.
     *
     * @return array<string, mixed>
     */
    public function toRemotePayload(): array
    {
        return [
            'name' => $this->localKey,
            'kind' => 'client',
            'description' => $this->description,
            'parameters' => $this->parameters->toArray(),
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }
}
