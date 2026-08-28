<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

/**
 * Payload to create a chat inside a workspace. The chat selects the team
 * through the mandatory `team_id`; the server validates that the team exists
 * and belongs to the chat owner.
 */
final readonly class ChatCreateRequest
{
    public function __construct(
        public string $teamId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['team_id' => $this->teamId];
    }
}
