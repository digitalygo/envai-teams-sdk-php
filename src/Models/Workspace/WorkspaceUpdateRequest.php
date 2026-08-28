<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Workspace;

use Digitalygo\Envai\Models\Json\JsonArray;
use Digitalygo\Envai\Models\Json\JsonObject;

/**
 * Payload to update a workspace. At least one of `name` or `metadata` must
 * be provided; a null value is never legal for either field, so null simply
 * means "not provided" and toArray() omits it.
 */
final readonly class WorkspaceUpdateRequest
{
    public function __construct(
        public ?string $name = null,
        public JsonObject|JsonArray|null $metadata = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [];

        if ($this->name !== null) {
            $payload['name'] = $this->name;
        }

        if ($this->metadata !== null) {
            $payload['metadata'] = $this->metadata->toArray();
        }

        return $payload;
    }
}
