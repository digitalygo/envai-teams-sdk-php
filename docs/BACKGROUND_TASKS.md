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
SSE (including reconnects), task operations and tool results. Successful ATP
responses and SSE must acknowledge the extension; a missing acknowledgment after
a mutation is an uncertain outcome and never triggers an automatic retry. Existing clients
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

## Replay, reconnect and cancellation

Idempotent foreground helpers examine the complete pre-ping replay before yielding
executable requests: an already resolved call or terminal turn cannot execute again.
Pending requests still run once. Foreground helpers finish at their accepted turn's
terminal event; background subscriptions continue independently.

`$client->chat($workspace, $chat)->events()` reconnects on ordinary EOF or transient
read failures, preserving `after` and rejecting events for another chat before cursor
advancement. The default total disconnect budget is two attempts. Set
`maxReconnectAttempts` explicitly, or `reconnect: false` for a finite subscription.
Legacy contract 2 always remains finite. Exhaustion is an error, not a completed job.

Pass a `CancellationToken` through `RunnerOptions(cancellationToken: $token)`.
Handlers receive `$invocation->cancellationToken` and should call
`throwIfCancelled()` between work units. Observed task/turn termination and resolved
calls also cancel the corresponding invocation. A detached task is not cancelled by
its origin turn ending. PHP handlers are synchronous: arbitrary blocking application
code cannot be forcibly interrupted. The runner checks again after the handler and
never submits a late result after observed cancellation.

Memory and File call stores implement `ToolReplayBarrierStore`, preserving resolved
call and terminal task/turn barriers. File stores persist barriers in a `.barriers`
sidecar with the same locking and corruption checks; retain it together with the call
file. Give each account/deployment a distinct file path or database scope. Custom
stores can implement this optional interface to retain barriers across runner restarts.

## Read-only waiters and pagination

```php
$source = $client->workspaceFiles()->waitForSource($workspace, $fileId, token: $token);
$operation = $client->wiki()->waitForOperation($workspace, $operationId, token: $token);
$image = $client->images()->waitForGeneration($generationId, token: $token);
foreach ($client->completions()->iterate($workspace, $chatId) as $completion) {
    // Metadata only; no message content.
}
$page = $client->completions()->listPage($workspace, $chatId, page: 2);
```

Waiters only read existing resources and preserve failed/cancelled terminal states.
Source conversion completion is independent of indexing; recoverable conversion
failures keep polling. Each waiter has one total deadline and checks cancellation
before and after reads and during sleep. HTTP timeout precision is whole seconds;
blocking reads can overrun a fractional deadline by less than one second, after which
the result is rejected. Polling reads disable nested HTTP retries. They never create
an image generation, start a Wiki run or retry a mutation.

Pagination uses the Laravel page selector on the original trusted API route, never
follows server-provided URLs, and rejects non-advancing page metadata.

## Client and Laravel configuration

Additional `EnvaiConfig(headers: ['X-Tenant' => 'tenant'])` headers reach authenticated
API requests, capability preflight and SSE connections. SDK-controlled authentication,
contract, content, idempotency and routing headers cannot be overridden. Public JWKS,
webhooks and signed artifact requests do not inherit these headers. HTTP errors expose
`requestId()` from `X-Request-ID`.

Laravel connection configuration supports `native_tasks`, `jwks_url`, `headers`,
`connect_timeout_seconds`, `request_timeout_seconds`, `stream_timeout_seconds`, and
`retry_policy`, in addition to contract/replay settings. `retry_policy` accepts a
`RetryPolicy` or an array with `max_attempts`, `base_delay_ms`, `max_delay_ms`, `jitter`,
`retryable_statuses` and `respect_retry_after`. CI installs actual Illuminate 11, 12
and 13 dependencies and runs integration tests with skipped tests treated as failures.

`delegateAndWait(..., timeoutSeconds: 600)` uses a total monotonic deadline across
capability checks, admission, target streaming and origin task lookups. It checks the
origin task before replaying pending target tools, so retrying an already terminal job
returns its final output without executing stale tools.

For distributed workers use [the PostgreSQL tool-state adapter](POSTGRES_TOOL_STATE.md)
with a dedicated account/deployment scope and explicit schema migration.
