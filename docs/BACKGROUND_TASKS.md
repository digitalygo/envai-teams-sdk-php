# Native background tasks (opt in)

This source revision implements the native task extension reviewed in Envai server
PR 56. It does not establish compatibility of previously published PHP packages.
Use contract 3 and explicitly enable the extension on the client:

```php
use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;

$envai = new EnvaiClient(new EnvaiConfig(
    baseUrl: 'https://envai.example',
    apiKey: new SensitiveString($apiKey),
    nativeTasks: true,
    streamTimeoutSeconds: 600,
));
```

This sends `X-Envai-Contract-Version: 3` and
`X-Envai-Extensions: native-tasks-v1` consistently on capabilities, turn admission,
SSE (including reconnects), task operations and tool results. Existing clients
leave the extension disabled. Foreground `sendAndStream()` / `sendAndWait()` still
finish on their accepted turn's terminal event. Use a separate native client if
some chats should retain ordinary foreground behavior.

## Delegated job result

A delegation targets an existing chat owned by the same user in the same workspace.
Its team is determined by that target chat. A busy target is rejected with 409.
`turn_id` in the receipt is only the initial coordinator acknowledgment; it is not
the final job result. Detached children and later synthesis may continue afterward.

```php
use Digitalygo\Envai\Models\Chat\DelegationRequest;
use Digitalygo\Envai\Models\Chat\TaskStatus;

// $runner is the normal ClientToolRunner with your registry, verifier, call
// store and ChatsToolOutputSender. Its sender must use this native client.
$result = $envai->chat($workspaceId, $originChatId)->delegateAndWait(
    new DelegationRequest(
        workspaceId: $workspaceId,
        eventId: $stableEventId,
        originTurnId: $knownOriginTurnId,
        targetChatId: $targetChatId,
        prompt: 'Complete the analysis and return the final report.',
        notifyOrigin: false,
        strictModel: true,
    ),
    runner: $runner,
);
if ($result->status === TaskStatus::Completed) {
    $report = $result->output;
    $finalCoordinatorTurn = $result->resultTurnId;
} // Failed and Cancelled remain explicit terminal states; inspect error.
```

The helper requires `delegated_job_lifecycle`, confirms the target stream is live
before submission, preserves pending replay tools for exact-event-ID retries,
dispatches target tools through that stream, and polls the
origin task after each event (including heartbeats). It keeps the stream open
past the acknowledgment until the origin task is terminal, and closes it in all
exit paths. Without a runner, applications must provide another active target
consumer if the job can request client tools. PHP handlers are synchronous; keep
them short. Only one consumer should execute a chat's tools.

`notifyOrigin: false` requires `delegation_notify_origin` and suppresses automatic
origin model wake, while retaining task status, full final output and
`result_turn_id`. Use it for externally managed phase workflows. `strictModel`
requires `strict_model` and enforces the configured root model, including later
synthesis; child model resolution is unchanged. It is also available on
`ChatMessageRequest` and the runtime's message/stream/wait methods.

## Detached tools and replay

```php
// Explicitly task-aware; it does not terminate at an origin turn boundary.
$runner->handleBackgroundEvents($envai->chat($workspaceId, $chatId)->events(), $chatId);
```

This buffers tool requests during replay until the live ping. It observes all
replayed task/turn terminals and tool results first, then dispatches only pending
calls. Replay ending before a live ping executes no tools. At most 10,000 pending
requests are buffered. `liveConfirmed: true` is only for an iterable that has
already crossed a verified live boundary. `handleBackgroundEvent()` is the
single-event primitive for a caller that manages that boundary itself.

`task.event` retains `task_id`, `origin_turn_id`, `event_type` and the actual
`event_payload`. Inner client requests use the unchanged Ed25519 signature
verification and call-ID deduplication before invoking handlers. Object/array
argument distinctions are preserved. Tools post to the execution chat, never an
inferred origin chat. Cross-team tools remain on the target stream; the origin
does not forward them. A task terminal blocks later tools for that task while
an origin turn terminal does not block detached children. Use a persistent call
store across restarts; the in-memory store provides process-local deduplication.

## Low-level operations

`$envai->chats()` exposes:

| Method | Input | Result |
| --- | --- | --- |
| `deliver($chatId, $request)` | `DeliveryRequest` with workspace/event/origin/task IDs, `DeliveryOutcome`, output | `DeliveryAccepted` (`queued`, `delivered`, `cancelled`, `failed`) |
| `delegate($chatId, $request)` | `DelegationRequest` | `DelegationAccepted` (job task ID and initial turn ID) |
| `getTask($chatId, $taskId)` | IDs | `TaskSnapshot` with explicit status/output/error/deliveryError/resultTurnId |
| `cancelTask($chatId, $taskId, subtree: true)` | IDs and optional subtree boolean | `TaskCancelled` |
| `classifyPhase($chatId, $request)` | `PhaseClassificationRequest` | `PhaseClassificationResult` with `PhaseDecision` |

`TaskAwait::terminal($originEvents, $originChatId, $taskId)` waits for a typed
origin `task.completed`, `task.failed` or `task.cancelled`; coordinator turn events
cannot complete it. Callers using this lower-level waiter must separately keep
the target stream and its tool runner active. The high-level delegated helper
handles target subscription and final origin task lookup together.

All native operations check advertised capabilities before sending. Mutations
are never automatically retried. Stable delivery/delegation event IDs deduplicate
identical payloads while the chat is resident; changing payload conflicts.
Classification accepts any non-null JSON task context and a positive attempt.
Persist successful classification responses: a durable `request_id` cannot be
reused, and a 502/503 or transport failure is uncertain, not permission to retry
under a new ID. No helper automatically starts classification or provider calls.

Task state and delivery receipts can disappear after restart/eviction. A
`task_unknown` 404 or stream EOF is an unknown outcome, never evidence that work
did not execute. Cancellation is best effort and does not undo external effects.
Cancelling the origin turn does not cancel detached tasks. Cancelling a local
wait closes observation only: explicitly call `cancelTask` to stop execution.
Delivery failure can coexist with a successful task output (`delivery_error`).
EOF, timeout and cancellation do not silently convert a job to success or retry it.
