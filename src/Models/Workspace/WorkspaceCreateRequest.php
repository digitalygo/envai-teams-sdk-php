<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Workspace;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * Payload to create a workspace. There is no team field: the workspace does
 * not own a team.
 *
 * Request DTOs are plain constructors: unknown fields are rejected by the
 * server and are never serialized here. `metadata` follows the workspace
 * metadata shape (JSON object or list) and is omitted when absent, letting
 * the server apply its empty-array default.
 */
final readonly class WorkspaceCreateRequest
{
    public function __construct(
        public string $name,
        public JsonObject|JsonArray|null $metadata = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = ['name' => $this->name];

        if ($this->metadata !== null) {
            $payload['metadata'] = $this->metadata->toArray();
        }

        return $payload;
    }
}
