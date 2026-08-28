<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Models\Chat;

/**
 * Payload to send a user message to a chat. The message opens a turn and
 * becomes the turn input.
 */
final readonly class ChatMessageRequest
{
    public function __construct(
        public string $message,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['message' => $this->message];
    }
}
