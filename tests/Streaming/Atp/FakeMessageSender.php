<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tests\Streaming\Atp;

use Digitalygo\Envai\Models\Chat\ChatMessageAccepted;
use Digitalygo\Envai\Streaming\Atp\MessageSender;

/**
 * MessageSender fake that records every send and returns a fixed
 * acknowledgment.
 */
final class FakeMessageSender implements MessageSender
{
    /**
     * @var list<array{chatId: string, message: string}>
     */
    public array $calls = [];

    public function __construct(private readonly ChatMessageAccepted $accepted) {}

    public function send(string $chatId, string $message): ChatMessageAccepted
    {
        $this->calls[] = ['chatId' => $chatId, 'message' => $message];

        return $this->accepted;
    }
}
