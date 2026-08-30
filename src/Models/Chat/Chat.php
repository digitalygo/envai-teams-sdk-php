<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

use Digitalygo\Envai\Models\Common\ArrayHydratable;
use Digitalygo\Envai\Models\Internal\Wire;

/**
 * A chat inside a workspace with id `cht_<ulid>`. `team_id` selects the team
 * for the chat and is validated at creation to exist and belong to the chat
 * owner. Timestamps stay ISO 8601 strings (wire-faithful).
 */
final readonly class Chat implements ArrayHydratable
{
    /**
     * @param  array<string, mixed>  $extra  Unknown payload keys preserved for forward compatibility.
     */
    public function __construct(
        public string $id,
        public string $workspaceId,
        public string $userId,
        public string $teamId,
        public string $createdAt,
        public string $updatedAt,
        public array $extra = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        $context = 'Chat';

        return new self(
            id: Wire::string($data, 'id', $context),
            workspaceId: Wire::string($data, 'workspace_id', $context),
            userId: Wire::string($data, 'user_id', $context),
            teamId: Wire::string($data, 'team_id', $context),
            createdAt: Wire::string($data, 'created_at', $context),
            updatedAt: Wire::string($data, 'updated_at', $context),
            extra: Wire::extra($data, ['id', 'workspace_id', 'user_id', 'team_id', 'created_at', 'updated_at']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'id' => $this->id,
            'workspace_id' => $this->workspaceId,
            'user_id' => $this->userId,
            'team_id' => $this->teamId,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ], $this->extra);
    }
}
