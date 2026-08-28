<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Streaming\Atp;

use Digitalygo\Envai\Models\Chat\ChatMessageAccepted;

/**
 * Sends a message to the chat and returns the accepted acknowledgment.
 *
 * The transport is the caller's concern: implement this against the chat
 * HTTP client, typically bound to the chat id of the stream.
 */
interface MessageSender
{
    /**
     * Sends a message that opens a turn.
     */
    public function send(string $chatId, string $message): ChatMessageAccepted;
}
