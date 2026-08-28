<?php

declare(strict_types=1);

namespace Digitalygo\Envai\Tools\Execution;

/**
 * Delivers a client tool output to the platform.
 *
 * The wire contract is POST /api/v1/chats/{chat_id}/tool-output with
 * `{call_id, output, is_error}`; an adapter over ChatsService::resolveToolOutput
 * is the natural implementation. Senders may throw ApiException (409
 * tool_already_resolved / tool_timed_out, 404 after the terminal event) and
 * TransportException; the runner classifies those failures against the call
 * store.
 */
interface ToolOutputSender
{
    public function submit(string $chatId, string $callId, string $output, bool $isError): void;
}
