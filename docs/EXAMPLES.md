# Examples and recipes

These examples target the public PHP API in version 0.2.0 and later compatible
0.2 releases. Code under `examples/` is available in a source checkout; it is
excluded from Composer distribution archives. Install dependencies in the
checkout with `composer install` before running a script.

## Configure an account

Set `ENVAI_BASE_URL` to your Envai API origin and provide `ENVAI_API_KEY` through
your environment or a secret manager. Do not put the key in a command committed
to source control. `examples/config.php` checks both variables, loads Composer's
autoloader and returns an `EnvaiClient`.

The base URL normally contains only the origin. `/api/v1` is appended once; an
already-prefixed API URL is also accepted. The default JWKS endpoint is resolved
from the origin. For separate JWKS hosting, set `EnvaiConfig::$jwksUrl` explicitly.
Use HTTPS for real account traffic.

```bash
php examples/quickstart.php
```

This reads capabilities and the model catalog. It performs no remote writes.
Choose model IDs from your account's returned catalog rather than assuming an
example model is available.

## Typed HTTP operations

Each service returns typed models. For example, creating a workspace is an
explicit remote mutation:

```php
use Digitalygo\Envai\Models\Workspace\WorkspaceCreateRequest;

$envai = require __DIR__.'/examples/config.php';
$workspace = $envai->workspaces()->create(
    new WorkspaceCreateRequest(name: 'SDK example'),
);

echo $workspace->id;
```

The path above is relative to a script in the repository root. In an installed
application, use its own Composer autoloader and configuration instead.

To create a chat, supply an existing team ID belonging to the same account:

```php
use Digitalygo\Envai\Models\Chat\ChatCreateRequest;

$created = $envai->chats()->createChat(
    $workspace->id,
    new ChatCreateRequest($teamId),
);
$chat = $envai->chat($workspace->id, $created->id);
```

Alternatively, synchronize local definitions and resolve a team by its local
key with `$envai->definitions()->team('forecasting')`. The readiness gate checks
that the compiled local definitions match the last successfully applied state.

## Send one message and stream its turn

For the runnable example, set `ENVAI_WORKSPACE_ID` and `ENVAI_CHAT_ID` to an
existing workspace and chat. The command sends one message, which can start
billable model activity:

```bash
php examples/streaming.php 'Summarize the latest findings.'
```

`sendAndStream()` opens a stream, waits for live confirmation, sends the message
and yields only events for the accepted turn. It stops at the first terminal
event. `sendAndWait()` instead returns a `TurnResult`; inspect its terminal type
before treating the turn as successful.

An existing chat's raw stream can be consumed without sending a message:

```php
$stream = $envai->chat($workspaceId, $chatId)->events(after: $lastSeenCursor);

try {
    foreach ($stream as $event) {
        // Apply the event to your application state.
        $lastSeenCursor = $stream->lastCursor();
    }
} finally {
    $stream->close();
}
```

Supply an integer cursor associated with this chat; use `0` for an initial
stream. Persisting a cursor does not make the server's replay window durable.
A clean EOF ends iteration. A gap that cannot be repaired raises
`ReplayGapException`; reconcile the application state instead of assuming the
missing events were processed. Do not resend a message just because its stream
was interrupted.

## Register local tools and synchronize definitions

The source-checkout example contains agent Markdown, a team definition and a
registry under `examples/definitions`. The tool handlers in
`examples/client-tool.php` are demonstrations: database and price lookups return
stub data and must be replaced before production use. The three agent files
use an illustrative model ID; choose one available in your catalog.

```bash
php examples/sync-workflow.php
```

By default this validates definitions and reads the remote state to produce a
plan. It does not apply changes. Review collisions, drift and required model
IDs, then explicitly apply if the proposed changes are wanted:

```bash
php examples/sync-workflow.php --apply
```

The example does not enable adoption, overwrite or pruning. In a real
application, put autoloadable handlers in your own namespace and copy the
wanted definitions to its configured definitions directory. Keep local keys
stable, keep credentials out of definition files, and use a separate state-store
identity for each server/account connection.

## Verify and execute signed client-tool requests

A tool must exist in both the local registry and the account's remote registry,
and its name must be enabled on the relevant agent. `examples/client-tool.php`
shows the full durable-runtime wiring:

- `ClientToolRegistry` maps public tool names to local handlers
- `JwksResolver` and `ToolRequestVerifier` validate the Ed25519 signature
- `FileClientToolCallStore` preserves local call deduplication across restarts
- `ChatsToolOutputSender` submits the controlled output

With `ENVAI_WORKSPACE_ID` and `ENVAI_CHAT_ID` set, run:

```bash
php examples/client-tool.php
```

This listens to the selected chat and can execute enabled demonstration tools
and submit outputs. It does not send a new user message. It is a long-running
example; stop it when no longer needed. Point its call store at durable local
storage outside a web root. Multiple hosts require a shared store with atomic
claims rather than independent local files.

The signature covers the call ID, tool name and canonical arguments. It does
not authenticate the envelope chat, turn or timestamp, and it is not a proof of
freshness. Only feed the intended authenticated chat stream to the runner. Use
application-level authorization and idempotency for external effects; a process
crash can leave an operation ambiguous. Do not delete the call store to force a
retry.

Avoid exposing arbitrary SQL or shell execution through a production tool.
Use a narrowly scoped handler, parameterized operations, least-privilege
credentials and explicit I/O deadlines. PHP handlers are synchronous; the
runner's timeout check cannot interrupt a blocked call or undo its effects.

## Preserve JSON object and array shapes

The schema validator supports Draft 6, 7, 2019-09 and 2020-12; omission of
`$schema` selects 2020-12. A schema is validated before the tool can run.
Unresolved external references, mixed dialects and custom dialects fail closed.

In schema/map positions, empty PHP arrays are interpreted as empty objects.
In literal positions such as `const`, `enum`, `default` and `examples`, `[]`
means an empty JSON array. Use `new stdClass`, explicit `JsonObject`/`JsonArray`
values, or `json_decode($json, false)` when nested empty or numeric-key objects
must stay objects. `JsonObject::fromJson()` uses associative decoding and does
not preserve every nested object/list distinction.

```php
$schema = [
    'type' => 'object',
    'properties' => [
        'options' => [
            'type' => 'object',
            'const' => new stdClass,
        ],
    ],
    'required' => ['options'],
];
```

Use strings for identifiers and precision-sensitive numbers that cannot be
represented exactly by IEEE-754 binary64. Arguments executed by the handler
follow the canonical numeric semantics covered by the signature. See the
[compatibility guide](CURRENT_SERVER_COMPATIBILITY.md#runtime-safeguards) for
normalization, recursion and legacy-state caveats.

## Handle failures without duplicating mutations

```php
use Digitalygo\Envai\Exceptions\AuthenticationException;
use Digitalygo\Envai\Exceptions\EnvaiException;

try {
    $capabilities = $envai->capabilities()->getCapabilities();
} catch (AuthenticationException $exception) {
    // Fix the configured account/key; do not loop with unchanged credentials.
} catch (EnvaiException $exception) {
    // Classify using the exception type and structured API error.
    $requestId = $exception->requestId();
    $error = $exception->apiError();
}
```

Retry-safe reads can be retried by the configured retry policy. Ambiguous
mutations, message sends and tool-output delivery are not blindly retried.
After an uncertain result, read/reconcile the relevant state before issuing
another mutation. Avoid logging keys, signed URLs, arguments or outputs while
diagnosing a failure.

For image downloads, use the opaque generation/artifact IDs and the signature
and expiry from the server-issued URL. Do not substitute a storage path, invent
a signature or attach the bearer token manually. The
[artifact migration guide](CURRENT_SERVER_COMPATIBILITY.md#artifact-download-migration)
shows the current method.

## Laravel

[examples/laravel-usage.php](../examples/laravel-usage.php) is an application
recipe, not a standalone CLI script. The package supports auto-discovery;
register the provider manually only when discovery is disabled. Publish the
configuration/migrations, choose the database connection and run migrations
through your application's normal deployment process. Synchronization does
not run during provider boot.
