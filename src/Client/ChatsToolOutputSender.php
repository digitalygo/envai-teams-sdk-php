<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Client;

use Digitalygo\Envai\Models\Chat\ToolOutputRequest;
use Digitalygo\Envai\Tools\Execution\ToolOutputSender;

/**
 * ToolOutputSender adapter over the chat HTTP client: delivers a client
 * tool output through ChatsService::resolveToolOutput (POST
 * /chats/{chat_id}/tool-output, HTTP 204).
 *
 * The runner classifies failures: ApiException (409 tool_already_resolved
 * / tool_timed_out, 404 after the terminal event) and TransportException
 * are mapped onto the call store without a blind retry.
 */
final readonly class ChatsToolOutputSender implements ToolOutputSender
{
    public function __construct(
        private readonly ChatsService $chats,
    ) {}

    public function submit(string $chatId, string $callId, string $output, bool $isError): void
    {
        $this->chats->resolveToolOutput($chatId, new ToolOutputRequest($callId, $output, $isError));
    }
}
