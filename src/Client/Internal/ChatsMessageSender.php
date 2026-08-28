<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client\Internal;

use Digitalygo\Envai\Client\ChatsService;
use Digitalygo\Envai\Models\Chat\ChatMessageAccepted;
use Digitalygo\Envai\Models\Chat\ChatMessageRequest;
use Digitalygo\Envai\Streaming\Atp\MessageSender;

/**
 * MessageSender adapter over the chat HTTP client: sends a message that
 * opens a turn through ChatsService::sendMessage, returning the accepted
 * acknowledgment. Used by ChatRuntime to drive ChatTurnRuntime.
 */
final readonly class ChatsMessageSender implements MessageSender
{
    public function __construct(
        private readonly ChatsService $chats,
    ) {}

    public function send(string $chatId, string $message): ChatMessageAccepted
    {
        return $this->chats->sendMessage($chatId, new ChatMessageRequest($message));
    }
}
