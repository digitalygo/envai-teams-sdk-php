<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

final readonly class DelegationRequest
{
    public function __construct(
        public string $workspaceId,
        public string $eventId,
        public string $originTurnId,
        public string $targetChatId,
        public string $prompt,
        public ?bool $notifyOrigin = null,
        public ?bool $strictModel = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'workspace_id' => $this->workspaceId,
            'event_id' => $this->eventId,
            'origin_turn_id' => $this->originTurnId,
            'target_chat_id' => $this->targetChatId,
            'prompt' => $this->prompt,
        ] + ($this->notifyOrigin === null ? [] : ['notify_origin' => $this->notifyOrigin]) + ($this->strictModel === null ? [] : ['strict_model' => $this->strictModel]);
    }
}
