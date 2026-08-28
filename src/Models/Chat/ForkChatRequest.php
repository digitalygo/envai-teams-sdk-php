<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

/**
 * Payload to fork a chat into an existing destination chat. The destination
 * must belong to the same workspace and user and carry no history and no
 * active turn.
 */
final readonly class ForkChatRequest
{
    public function __construct(
        public string $destinationChatId,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['destination_chat_id' => $this->destinationChatId];
    }
}
