<?php

declare(strict_types=1);

/*
 * Sends one message to an existing chat and streams its accepted turn.
 * Set ENVAI_BASE_URL, ENVAI_API_KEY, ENVAI_WORKSPACE_ID and ENVAI_CHAT_ID.
 * Run: php examples/streaming.php 'Summarize the latest findings.'
 * This sends a real message and can start billable model activity.
 */

use Digitalygo\Envai\Exceptions\ReplayGapException;
use Digitalygo\Envai\Streaming\Events\AgentTextDeltaEvent;
use Digitalygo\Envai\Streaming\Events\TurnFailedEvent;

$envai = require __DIR__.'/config.php';
$workspaceId = getenv('ENVAI_WORKSPACE_ID');
$chatId = getenv('ENVAI_CHAT_ID');

if ($workspaceId === false || $workspaceId === '' || $chatId === false || $chatId === '') {
    throw new RuntimeException('Set ENVAI_WORKSPACE_ID and ENVAI_CHAT_ID.');
}

$chat = $envai->chat($workspaceId, $chatId);
$message = $argv[1] ?? 'Summarize the latest findings.';

try {
    foreach ($chat->sendAndStream($message) as $event) {
        if ($event instanceof AgentTextDeltaEvent) {
            echo $event->delta;
        } elseif ($event instanceof TurnFailedEvent) {
            fwrite(STDERR, "The turn failed.\n");
        }
    }
} catch (ReplayGapException) {
    fwrite(STDERR, "Replay is incomplete. Reconcile the chat state before retrying.\n");
    exit(1);
}

// This text-only example does not execute client tools. When the selected team
// uses them, dispatch AgentToolRequestEvent to a configured tool runner too.
echo "\n";
