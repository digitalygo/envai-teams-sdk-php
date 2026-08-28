<?php

declare(strict_types=1);

/*
 * Illustrative example: ATP v2 chat streaming, turn helpers and cursor
 * semantics.
 *
 * Run with:
 *
 *     ENVAI_API_KEY=... php examples/streaming.php
 *
 * Replace the workspace and chat ids with real values.
 */

use Digitalygo\Envai\Exceptions\ReplayGapException;
use Digitalygo\Envai\Streaming\Events\AgentTextDeltaEvent;
use Digitalygo\Envai\Streaming\Events\TurnCompletedEvent;
use Digitalygo\Envai\Streaming\Events\TurnFailedEvent;
use Digitalygo\Envai\Streaming\Events\UnknownEvent;

$envai = require __DIR__.'/config.php';
$chat = $envai->chat('wsp_xxxxxxxx', 'cht_xxxxxxxx');

// 1. Open the typed event stream, replaying every retained event
//    strictly after the cursor. The stream is single-reader: consume it
//    once.
$stream = $chat->events(after: 0);

foreach ($stream as $event) {
    if ($event instanceof AgentTextDeltaEvent) {
        echo $event->delta;
    }

    if ($event instanceof UnknownEvent) {
        // Unknown event types are preserved for forward compatibility.
        printf("[unknown event] type=%s seq=%d\n", $event->envelope->type, $event->envelope->seq);
    }
}

printf("Final cursor: %d\n", $stream->lastCursor());

// 2. Send a message and stream every event of the accepted turn until
//    its first terminal event. Events of other turns are consumed by
//    the shared cursor but not yielded.
foreach ($chat->sendAndStream('What changed this week?') as $event) {
    if ($event instanceof AgentTextDeltaEvent) {
        echo $event->delta;
    }
}

// 3. Send a message and await the terminal event of the accepted turn.
$result = $chat->sendAndWait('Summarize the findings.');

if ($result->terminal instanceof TurnCompletedEvent) {
    echo $result->orchestratorText ?? '';
} elseif ($result->terminal instanceof TurnFailedEvent) {
    fwrite(STDERR, "The turn failed.\n");
}

printf("Last cursor after the turn: %d\n", $result->lastSeq);

// 4. Sequence gaps. When the replay window is exhausted the stream
//    reconnects with after=cursor; if the gateway no longer retains the
//    events, ReplayGapException is raised with the missing range.
try {
    foreach ($chat->events(after: $result->lastSeq) as $event) {
        // Replay of everything after the cursor; a gap raises below.
    }
} catch (ReplayGapException $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
}
