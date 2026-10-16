# Envai PHP SDK

Contract 3 is selected by default. See [migration and explicit legacy read-only mode](docs/CONTRACT_V3.md) before upgrading.

The official PHP SDK for Envai: a framework-independent, typed HTTP client and
ATP v3 (with historical v2 replay) runtime for chats, event streaming, declarative agents and teams,
configuration synchronization, and verified client tools. Laravel integration
is optional.

Version 0.2.1 is available on [Packagist](https://packagist.org/packages/digitalygo/envai-teams-sdk-php). This pre-1.0 SDK supports the contract
snapshot bundled in the repository; optional features also depend on the server
capabilities. See the [documentation index](docs/README.md),
[examples](docs/EXAMPLES.md), and
[compatibility and safety guide](docs/CURRENT_SERVER_COMPATIBILITY.md).

## Installation

Requirements: PHP 8.2 or later in the PHP 8 series, Composer, and the `curl`,
`json`, `mbstring`, `pdo` and `sodium` extensions. Additional PDO drivers are
needed only for their corresponding state stores.

Install the published package with Composer:

```bash
composer require digitalygo/envai-teams-sdk-php:^0.2.1
```

Composer resolves the package through Packagist. No custom `repositories`
entry or GitHub personal access token is required for normal installation.
If your application has an SDK-specific VCS entry from an earlier installation,
remove that entry to use Packagist; retain entries needed by other packages.
Never commit Composer authentication files.

Commit `composer.json` and `composer.lock`; deploy with `composer install` to
reuse the reviewed dependency versions. The Composer name is
`digitalygo/envai-teams-sdk-php`; PHP namespaces remain `Digitalygo\Envai`.
See the [migration notes](docs/CURRENT_SERVER_COMPATIBILITY.md#upgrading-from-01).

## Quick start

Configure `ENVAI_BASE_URL` with your Envai API origin and `ENVAI_API_KEY` through
your environment or secret manager. The following reads server capabilities;
it does not create a workspace or send a chat message.

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;

$baseUrl = getenv('ENVAI_BASE_URL');
$apiKey = getenv('ENVAI_API_KEY');

if ($baseUrl === false || $baseUrl === '' || $apiKey === false || $apiKey === '') {
    throw new RuntimeException('Set ENVAI_BASE_URL and ENVAI_API_KEY.');
}

$envai = new EnvaiClient(new EnvaiConfig(
    baseUrl: $baseUrl,
    apiKey: new SensitiveString($apiKey),
));

$capabilities = $envai->capabilities()->getCapabilities();
printf("ATP v%d\n", $capabilities->protocolVersion);
printf("Features: %s\n", implode(', ', $capabilities->features));
```

For an existing workspace and chat, use `$envai->chat($workspaceId, $chatId)`.
Calling `sendAndStream()` sends a new message and consumes the accepted turn:

```php
use Digitalygo\Envai\Streaming\Events\AgentTextDeltaEvent;

$chat = $envai->chat($workspaceId, $chatId);

foreach ($chat->sendAndStream('Summarize the latest findings.') as $event) {
    if ($event instanceof AgentTextDeltaEvent) {
        echo $event->delta;
    }
}
```

Supply real IDs owned by the configured account. The helper waits for live
subscription confirmation before sending. If the team uses client tools, also
handle its tool-request events with a configured client-tool runner; a text-only
loop does not execute tools. See [client tools](#client-tools).

## Versioning

Install tagged releases and review their release notes before upgrading. The SDK is pre-1.0; compatibility changes may occur between minor versions. A `^0.2.1` Composer constraint selects compatible 0.2 releases starting at 0.2.1 rather than a development branch.

Requirements: PHP `^8.2`; the `curl`, `json`, `mbstring`, `pdo` and `sodium` extensions; a PSR-3 logger if you want log injection; Symfony Console and Symfony YAML for the core CLI.

The core does not depend on Laravel. Illuminate packages (`illuminate/config`, `illuminate/console`, `illuminate/contracts`, `illuminate/database`, `illuminate/support`) are optional suggestions used only by the Laravel integration. PDO drivers for MySQL, PostgreSQL and SQLite are optional and only needed for the database state store.

### Prerelease channels

Use a prerelease only when the corresponding tag exists and its release notes
match the deployment you are testing. Composer hides prerelease versions when
`minimum-stability` is `stable`, the default. To permit a beta within an
otherwise compatible version range, use a package-specific stability flag:

```json
{
    "require": {
        "digitalygo/envai-teams-sdk-php": "^0.2@beta"
    }
}
```

`@beta` permits beta and more stable releases (RC and stable), not alpha or dev.
`@alpha` permits alpha and more stable releases, not dev. These examples explain
stability constraints; check the available tags before selecting a prerelease.

## Framework-independent configuration

The client is configured with an immutable `EnvaiConfig` and constructed as an `EnvaiClient`:

```php
use Digitalygo\Envai\Client\EnvaiClient;
use Digitalygo\Envai\Configuration\EnvaiConfig;
use Digitalygo\Envai\Configuration\SensitiveString;

$config = new EnvaiConfig(
    baseUrl: 'https://api.envai.example',
    apiKey: new SensitiveString(getenv('ENVAI_API_KEY')),
    connectionName: 'production',
);

$envai = new EnvaiClient($config);
```

Configuration notes:

- `baseUrl` is normally the API origin. The resolver appends `/api/v1` exactly once and also accepts a URL already ending in that prefix. JWKS is resolved from the origin, not from the API path; use `jwksUrl` explicitly when it is hosted elsewhere.
- `apiKey` is wrapped in a `SensitiveString`; secrets are never printed and never written to the state store.
- `jwksUrl` defaults to the well-known JWKS endpoint derived from the base URL origin; see `effectiveJwksUrl()`.
- `connectionName` identifies the connection in the sync state store; the same application can hold several `EnvaiClient` instances, one per connection.
- Timeouts: `connectTimeoutSeconds` (default 10), `requestTimeoutSeconds` (default 60) and `streamTimeoutSeconds` (nullable).
- `headers` adds default request headers, `retryPolicy` tunes retry behavior, and `definitionsPath` / `stateDriver` / `stateFilePath` set the defaults used by the definitions facade and CLI.

The default transport is `CurlTransport`. You can inject any transport, but it must implement both `HttpTransport` and `EventStreamTransport` because chat streaming shares the transport; the constructor rejects a transport that only implements `HttpTransport`. A PSR-3 logger can be passed as the third constructor argument.

Retries are conservative: only retry-safe operations are retried automatically. Ambiguous mutations (creating resources, sending messages, forking, restoring, generating images, resolving client tools, reconciler apply) are never retried automatically. `RetryPolicy` accepts `maxAttempts`, `baseDelayMs`, `maxDelayMs`, `jitter`, `retryableStatuses` and `respectRetryAfter`.

## Laravel configuration

The package declares Laravel auto-discovery. If your application disables discovery, register the provider once in `bootstrap/providers.php` (or `config/app.php` where applicable):

```php
return [
    // Keep the other providers used by your application.
    Digitalygo\Envai\Integration\Laravel\EnvaiServiceProvider::class,
];
```

Publish the configuration and the migration:

```bash
php artisan vendor:publish --tag=envai-config
php artisan vendor:publish --tag=envai-migrations
```

The published `config/envai.php` mirrors the framework-independent defaults:

```php
return [
    'default' => 'production',
    'connections' => [
        'production' => [
            'base_url' => env('ENVAI_BASE_URL'),
            'api_key' => env('ENVAI_API_KEY'),
        ],
    ],
    'definitions' => [
        'path' => base_path('envai'),
        'sync_mode' => env('ENVAI_SYNC_MODE', 'deploy'),
    ],
    'state' => [
        'driver' => env('ENVAI_STATE_DRIVER', 'file'),
        'file' => base_path('.envai'),
        'connection' => env('ENVAI_STATE_DB_CONNECTION'),
        'table_prefix' => 'envai_sync_',
    ],
];
```

The provider registers one `envai.client.<connection>` singleton per configured connection, `envai.client` with the `envai` alias for the default connection, and `envai.definitions` with the `DefinitionsService::class` alias for the default definitions facade (wired with a state store resolver that understands the `laravel` driver).

Artisan aliases of the core commands:

```text
envai:validate
envai:plan
envai:sync
envai:status
envai:state
```

The provider never synchronizes at boot, never opens streams and never creates tables. The published migration is only executed when you decide to run it, on the connection you choose. All bindings are lazy: nothing touches the network or the database until a service is resolved.

### Lazy sync mode

For local development, set `ENVAI_SYNC_MODE=lazy` (or the config key). `LazySynchronizer::ensureSynchronized($definitions, $mode, $ttl)` is the gate: the first high-level operation checks the local hash and the ready manifest; when the last ready manifest is older than the TTL or its hash differs from the freshly compiled definitions, the synchronizer acquires the lock and applies the sync before the operation proceeds. Failures propagate unchanged, so a broken remote never silently opens the gate. Supported modes are `deploy`, `lazy`, `manual` and `off`; any other mode throws `InvalidArgumentException`. In `deploy` mode no automatic gate runs; synchronization is done explicitly through the CLI.

## HTTP client

`EnvaiClient` exposes one typed service per API family: `workspaces()`, `tools()`, `workspaceFiles()`, `chats()`, `completions()`, `agents()`, `teams()`, `models()`, `apiKeys()`, `images()` and `capabilities()`.

Common calls:

```php
use Digitalygo\Envai\Models\Workspace\WorkspaceCreateRequest;
use Digitalygo\Envai\Models\Chat\ChatMessageRequest;

$workspace = $envai->workspaces()->create(new WorkspaceCreateRequest(name: 'Forecasting'));
$workspaces = $envai->workspaces()->list();                    // ResourceCollection
$chat = $envai->chats()->getChat($workspaceId, $chatId);
$accepted = $envai->chats()->sendMessage($chatId, new ChatMessageRequest('Hello'));
$tools = $envai->tools()->list();                              // user-scoped registry
$tool = $envai->tools()->get('query_forecasting_database');
$agents = $envai->agents()->list();
$teams = $envai->teams()->list();
$models = $envai->models()->listModels();
$capabilities = $envai->capabilities()->getCapabilities();
$health = $envai->capabilities()->healthCheck();
```

Reads return typed DTOs (or `ResourceCollection` for lists) and are retry-safe; mutations take typed request models, are classified as ambiguous and are never retried automatically. The low-level client is usable with explicit remote ids without any synchronization.

## Local definitions

The declarative source of truth lives in the `envai/` directory by default:

```text
envai/
├── agents/
│   └── *.md
├── teams/
│   └── *.php
└── tools.php
```

The path can be overridden through `EnvaiConfig::$definitionsPath`, the Laravel `envai.definitions.path` config key, or the CLI `--definitions-path` option.

The directory contains only the desired state. It never contains API keys, remote ids, SSE checkpoints, client tool call state or applied sync state; those live in the state store.

## Markdown agents

Each agent is a Markdown document with YAML frontmatter. The file name without `.md` is the immutable local key; teams reference that key. The frontmatter `name` is the mutable remote name.

```markdown
---
format: 1
name: researcher
description: Research specialist
mode: subagent
model: openai/gpt-5-mini

tools:
  server:
    - exa_search
    - exa_contents
  client:
    - query_forecasting_database

permissions:
  read: allow
  write: deny

can_write_knowledge: false
can_write_skills: false

config:
  temperature: 0.2
  timeout_seconds: 180
---

You are a research specialist.

Collect evidence for the assigned task and report uncertainty explicitly.
```

Frontmatter mapping:

| Local field | Envai payload | Notes |
|---|---|---|
| Markdown body | `system_prompt` | non-empty body required |
| `name` | `name` | remote name, mutable |
| `description` | `description` | |
| `mode` | `mode` | `primary` or `subagent` |
| `model` | `model` | must exist in the model catalog when specified |
| `tools.server` | `tools_enabled` | server-side tool names |
| `tools.client` | `external_tools_enabled` | compiled as `AgentTool` objects, not flat strings |
| `permissions` | `permissions` | |
| `can_write_knowledge` | `can_write_knowledge` | |
| `can_write_skills` | `can_write_skills` | |
| `config` | `config` | must not contain `max_tokens` at any depth |

The compiler validates frontmatter syntax, the `format` version, remote name uniqueness, the `mode` value, model catalog membership, tool reference resolution and team mode compatibility before anything is written remotely.

## Native tools

A native client tool is a PHP class implementing the `ClientTool` contract:

```php
use Digitalygo\Envai\Tools\Contracts\ClientTool;
use Digitalygo\Envai\Tools\Contracts\ToolInvocation;
use Digitalygo\Envai\Tools\Contracts\ToolResult;

final class QueryForecastingDatabase implements ClientTool
{
    public function description(): string
    {
        return 'Runs a read-only query against the forecasting database.';
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string'],
                'limit' => ['type' => 'integer', 'default' => 10],
            ],
            'required' => ['query'],
        ];
    }

    public function handle(ToolInvocation $invocation): string|ToolResult
    {
        return ToolResult::json(['rows' => $this->runQuery($invocation->arguments['query'])]);
    }
}
```

- `ToolInvocation` carries `chatId`, `turnId`, `callId` and the validated `arguments`.
- `handle()` returns a plain string or a `ToolResult`; `ToolResult::json()` serializes structured payloads once, at construction time.
- The schema root must be an object; the compiler normalizes the schema and rejects non-object roots.
- `timeout_seconds` is clamped to the platform range 1..600.

The registry is the `envai/tools.php` file. The array key is the remote Envai tool name, not the class basename:

```php
use App\Ai\Tools\QueryForecastingDatabase;
use App\Ai\Tools\RetrieveMarketPrices;

return [
    'query_forecasting_database' => QueryForecastingDatabase::class,
    'retrieve_market_prices' => [
        'class' => RetrieveMarketPrices::class,
        'timeout_seconds' => 60,
    ],
];
```

Entries without a timeout default to 120 seconds. The same shape is accepted by `ClientToolRegistry::fromArray()`.

Client tools are user-scoped: a registered tool is shared across all workspaces of the same Envai user. An agent only sees a tool when the tool is registered and its name appears in the agent's `tools.client` list.

## PHP teams

Each team is a declarative PHP file returning a `Team::make()` builder:

```php
<?php

use Digitalygo\Envai\Definitions\Team;

return Team::make('forecasting')
    ->name('Forecasting team')
    ->description('Researches and produces forecasts')
    ->orchestrator('orchestrator')
    ->member('researcher', role: 'research')
    ->member('forecaster', role: 'forecasting');
```

- The key passed to `Team::make()` must match the file name.
- `orchestrator()` and `member()` take local agent keys; the reconciler resolves them to remote agent ids (`agt_*`).
- Member order is significant and preserved.
- Validation requires: an existing orchestrator in `primary` mode, existing and distinct `subagent` members, the orchestrator not listed as a member, valid team roles and a unique remote name.

## File state store

The default sync state store is a versioned JSON file at `.envai`:

```json
{
  "format": 1,
  "connections": {
    "production": {
      "base_url": "https://api.envai.example",
      "ready_manifest": "sha256:...",
      "resources": {
        "tool:query_forecasting_database": {
          "remote_id": "twl_...",
          "remote_name": "query_forecasting_database",
          "desired_hash": "sha256:...",
          "last_applied": {}
        }
      }
    }
  }
}
```

Guarantees of the file store (`FileSyncStateStore`): atomic writes through a temporary file plus rename, exclusive locking via `flock` on a dedicated `.envai.lock` file, restrictive permissions (`0600`) when the platform supports them, fail-closed reads (a corrupt or unknown-format file throws instead of being silently recreated), and no credentials or SSE checkpoints stored.

Add the state files to `.gitignore`:

```gitignore
.envai
.envai.lock
```

The file store is suitable for a single host, CI and local development. Multi-node deployments should use a database state store so all nodes share one state.

## Database state store

Bring your own database and driver. Two implementations ship with the SDK, both with the same logical schema: `envai_sync_resources` keyed uniquely by `(connection, resource_type, local_key)` and holding `remote_id`, `remote_name`, `desired_hash`, `last_applied_payload`, `remote_marker`, `status`, `synchronized_at`, `created_at` and `updated_at`; plus `envai_sync_manifests` (one row per connection, the last manifest marked ready) and a `locks` table used for cross-process locking.

### PDO driver

`PdoSyncStateStore` accepts any consumer-supplied PDO instance for `sqlite`, `mysql` or `pgsql`:

```php
use Digitalygo\Envai\Sync\State\Pdo\PdoSyncStateStore;

$pdo = new \PDO('sqlite:' . __DIR__ . '/state.sqlite');
$store = new PdoSyncStateStore($pdo, tablePrefix: 'envai_sync_', autoMigrate: true);

$store->ensureSchema(); // creates the resources, manifests and locks tables
```

### Laravel driver

`LaravelDatabaseSyncStateStore` wraps any Illuminate connection and is selected through the `laravel` state driver:

```php
use Digitalygo\Envai\Integration\Laravel\LaravelDatabaseSyncStateStore;

$store = new LaravelDatabaseSyncStateStore(DB::connection('envai'), 'envai_sync_');
```

Publish and run the migration:

```bash
php artisan vendor:publish --tag=envai-migrations
php artisan migrate
```

The consumer decides the connection, the table prefix, the database and when the migration runs. The provider never creates tables automatically. The `locks` table is ensured lazily and idempotently by the store on first lock acquisition, or you can create it yourself in the migration.

Custom stores can implement the `SyncStateStore` contract (Redis, DynamoDB, remote filesystems, proprietary databases). A custom store must guarantee locking and atomic per-resource updates, or explicitly declare a reduced capability.

## Plan and sync

The CLI is available through `vendor/bin/envai` after installation:

```bash
vendor/bin/envai definitions:validate
vendor/bin/envai definitions:plan
vendor/bin/envai definitions:sync
vendor/bin/envai definitions:status
vendor/bin/envai definitions:state
```

Environment variables: `ENVAI_BASE_URL` and `ENVAI_API_KEY` are mandatory; `ENVAI_CONNECTION` (default `default`), `ENVAI_DEFINITIONS_PATH` (default `envai`), `ENVAI_STATE_DRIVER` (default `file`) and `ENVAI_STATE_PATH` (default `.envai`) are optional.

Shared options:

| Option | Meaning |
|---|---|
| `--connection=` | connection name for the state store |
| `--definitions-path=` | local `envai/` definitions directory |
| `--state-driver=` | `inmemory`, `file` or `pdo` |
| `--dry-run` | plan and report without mutating anything |
| `--json` | machine-readable JSON output instead of text |
| `--only=tools\|agents\|teams` | restrict the plan to one resource kind |
| `--adopt` | take unmanaged remote resources with a matching name into management |
| `--overwrite-drift` | overwrite remote resources that changed after the last apply |
| `--recreate-missing` | recreate managed resources whose remote vanished |
| `--prune` | delete managed resources no longer declared by the manifest |
| `--fail-on-drift` | exit with code 2 when the plan contains drifted operations |

Semantics of the destructive overrides:

- `--adopt`: a remote resource with the same name that is not in the state store blocks by default; `--adopt` takes it into management and always shows the diff. Adoption never overwrites the remote automatically; combine with `--overwrite-drift` to adopt and overwrite in the same apply.
- `--overwrite-drift`: remote resources that changed after the last apply are blocked by default; this option authorizes overwriting them.
- `--recreate-missing`: managed resources whose remote vanished are blocked by default; this option authorizes recreating them.
- `--prune`: only resources already managed by the same connection are considered. Order: update teams to remove obsolete references, then delete managed teams, agents and tools. A deletion blocked by a dependency (a team with chats, a still-referenced agent) is reported, never circumvented. Unmanaged remote resources are never deleted.

The apply order is `tools -> agents -> teams`, so that agent tool references and team agent references are always satisfied. The manifest is only marked ready when every needed operation succeeded, every remote response was verified, the state store is updated and no resource is left `ambiguous` or `conflicted`. A partial `--only` scope never marks the overall manifest ready.

Programmatic usage:

```php
$plan = $envai->definitions()->plan();                       // three-way plan, no writes
$report = $envai->definitions()->apply();                    // plan + reconcile, one lock

$options = new SyncOptions(adopt: true, failOnDrift: true); // policy overrides
$report = $envai->definitions()->apply($options);
```

`plan()` holds the store lock for the local and remote phases and releases it before returning. `apply()` holds one lock acquisition across planning and reconciling, including the manifest readiness marking, and always releases it.

## Drift and conflicts

For every resource the planner compares three states: desired (local definitions), last applied (state store) and remote (observed on the API).

| Desired | Last applied | Remote | Result |
|---|---|---|---|
| same | same | same | unchanged |
| different | same | same | update |
| same | same | different | drift |
| different | same | different | conflict |
| present | absent | absent | create |
| present | absent | same name unmanaged | adoption required |
| present | present | absent | missing remote |
| absent | present | present | orphan managed |

The default policy fails closed:

```text
on_drift = fail
on_conflict = fail
on_missing_remote = fail
on_unmanaged_name_collision = fail
prune = false
```

Every override is explicit (`--adopt`, `--overwrite-drift`, `--recreate-missing`, `--prune`); no destructive override is activated implicitly in non-interactive environments.

When an apply receives a transport error after sending a mutation, the resource moves to `ambiguous`, the reconciler does not retry blindly, and it attempts a remote read to reconcile. If the outcome cannot be proven, the apply stops and the manifest is not marked ready.

## Deployment

The recommended production flow is a three step deploy pipeline:

```bash
vendor/bin/envai definitions:validate
vendor/bin/envai definitions:plan --fail-on-drift
vendor/bin/envai definitions:sync
```

Or with the Laravel aliases:

```bash
php artisan envai:validate
php artisan envai:plan --fail-on-drift
php artisan envai:sync
```

`definitions:validate` runs the pure local phase: compile, validate and cross-check references, with no remote reads and no lock. `definitions:plan --fail-on-drift` builds the three-way plan and fails the build with exit code 2 when any resource drifted. `definitions:sync` applies the plan and opens the readiness gate.

For multi-node deployments use a shared database state store; the file store is per host and is not shared between nodes.

## Workspace and chat creation

High-level chat creation is gated on definitions readiness. Create a workspace, then create a chat selecting a team by its local key:

```php
$definitions = $envai->definitions();
$definitions->requireSynchronized(); // throws DefinitionsNotSynchronizedException when out of date

$workspace = $envai->workspaces()->create(new WorkspaceCreateRequest(name: 'Forecasting'));

$chat = $envai->workspace($workspace->id)
    ->chats()
    ->create(team: $definitions->team('forecasting'));
```

- `team()` returns the remote team id for the local team key, gated on synchronization.
- `agent()` and `tool()` resolve the other resource kinds; tools return the remote name.
- `requireSynchronized()` compares the freshly compiled local manifest with the last manifest marked ready on the connection and throws when they differ or when no manifest was ever marked ready.
- Readiness is scoped to connection/account + manifest hash, never to a workspace.

Chat control plane operations per workspace: `chats()->list()`, `chats()->get($chatId)` and `chats()->create($team)`. The low-level `ChatsService` also exposes `cancel()`, `steer()`, `fork()`, `restore()`, `resolveToolOutput()` and `submitApproval()` with explicit remote ids.

## Streaming

Every chat exposes a typed ATP v3 (with historical v2 replay) event stream:

```php
$stream = $envai->chat($workspaceId, $chatId)->events();

foreach ($stream as $event) {
    // $event is a typed AtpEvent (TurnStartedEvent, AgentTextDeltaEvent,
    // TurnCompletedEvent, PingEvent, UnknownEvent, ...)
}
```

`ChatRuntime` turn helpers:

```php
$accepted = $chat->sendMessage('What changed this week?');        // HTTP 202 acknowledgment

foreach ($chat->sendAndStream('What changed this week?') as $event) {
    if ($event instanceof AgentTextDeltaEvent) {
        echo $event->delta;
    }
}

$result = $chat->sendAndWait('Summarize the findings.');           // TurnResult
echo $result->terminal::class;                                     // turn.completed / cancelled / failed
echo $result->orchestratorText;
echo $result->lastSeq;                                             // cursor after the terminal event
```

Stream details:

- `events(int $after = 0)` replays every retained event strictly after the cursor.
- The stream is single-reader: one instance is consumed once, with `foreach` or `getIterator()`.
- The cursor (`lastCursor()`) advances with every delivered replayable event; `ping` events are delivered as control events but never advance it; duplicates with `seq <= cursor` are skipped.
- On a sequence gap the stream closes the connection and reopens it with `after=cursor` (default up to 2 attempts with backoff and jitter). If the replay still starts beyond `cursor + 1`, it throws `ReplayGapException` with the missing range.
- `waitLiveConfirmation()` blocks until the first `ping`, proving the gateway registered the live subscription; this is the send-message race workaround used by the turn helpers.
- Known events decode to typed classes; unknown events are preserved as `UnknownEvent`; known events with invalid payloads become `ProtocolViolationEvent`.
- Close the stream deterministically with `close()`; the connection is also closed when iteration completes or is abandoned.

Turn helpers open their own stream per call, wait for live confirmation, send the message and only yield events of the accepted turn (matched by `turn_id`), while the shared cursor keeps advancing over everything.

## Client tools

The client tool runtime verifies signed `agent.tool.request` events, executes the registered handlers and delivers the outputs back to the chat.

### Wiring

```php
$runtime = $envai->clientTools(); // registry compiled from the local definitions

$results = $runtime->handleEvents($events, $chatId); // list<ToolRunResult>
```

With an explicit registry:

```php
use Digitalygo\Envai\Tools\Execution\ClientToolRegistry;

$registry = ClientToolRegistry::fromArray(require __DIR__ . '/envai/tools.php');
$runtime = $envai->clientTools($registry);
```

`ToolRunResult` reports `callId`, `outcome` (`executed`, `skipped_by_store`, `unknown_tool`, `tool_busy`, `invalid_arguments`, `timeout_exceeded`, `handler_error`, `output_too_large`, `delivery_failed`, ...), `outputSubmitted`, `storeStatus` and an optional `error`.

### Signature verification

Every request is verified before the handler runs:

- the signature algorithm must be exactly `EdDSA`;
- the public key is resolved from the JWKS by `kid` (Ed25519, one forced refresh per unknown kid);
- the preimage is exactly `call_id + "\n" + tool_name + "\n" + canonical_json(args)`, where only the arguments are canonicalized per RFC 8785;
- verification failures throw `ProtocolViolationException` and the handler is never invoked, nothing is submitted.

A valid signature authenticates the call ID, tool name and canonical arguments. It does not sign the envelope chat, turn or timestamp and does not prove freshness or exactly-once effects. Only consume the intended chat through a trusted authenticated stream. Keep durable deduplication state and use application-level idempotency for external side effects. See the [complete safety constraints](docs/CURRENT_SERVER_COMPATIBILITY.md#runtime-safeguards).

### Call store and at-most-once

The `ClientToolCallStore` tracks every call through `begin()`, `markSubmitted()`, `markResolved()` and `markAmbiguous()`, deduplicated by `(chatId, callId)`. The handler runs at most once: a store `begin()` that reports the call as already running, already resolved or already ambiguous skips execution entirely.

The default is `InMemoryClientToolCallStore`, intended for tests and single-process development. For production, construct the runtime with a durable call store, as shown in [the client-tool example](examples/client-tool.php). Creating a store alone does not attach it to `$envai->clientTools()`. `FileClientToolCallStore` coordinates processes sharing the same local file; a multi-host deployment needs a store with shared atomic claims. Keep a separate store for each server/account identity:

```php
use Digitalygo\Envai\Tools\State\FileClientToolCallStore;

$store = new FileClientToolCallStore(__DIR__ . '/var/envai-tool-calls.json');
```

Output delivery posts to `/chats/{chat_id}/tool-output` and maps failures without blind retries: `409 tool_already_resolved` treats the call as resolved, `409 tool_timed_out` and `404` mark it ambiguous, any other failure marks it ambiguous with the error recorded.

Controlled outcomes: unknown tools deliver `tool_not_found`, concurrency saturation delivers `tool_busy` (cap via `RunnerOptions::maxConcurrent`, default 4), invalid arguments deliver `invalid_arguments`, handler exceptions deliver a sanitized `handler_error` (never a stack trace), oversized outputs are replaced by `output_too_large` (131072 byte limit), and a handler that exceeds its conservative deadline (`timeout_seconds - submissionMarginSeconds`) is marked ambiguous so the server's own deadline auto-resolves the branch.

The PHP client-tool runner skips new requests for a turn after observing its terminal event through `handleEvents()` or `observe()`. This barrier belongs to the runner instance; existing call-store interfaces and durable deduplication of individual calls remain unchanged. Feed terminal events to each runner that processes a chat.

## Error handling

Every exception extends `EnvaiException` (a `RuntimeException`), which carries `context()`, `apiError()` and `requestId()`.

```text
EnvaiException
├── ApiException
│   ├── AuthenticationException     (401 / type unauthenticated)
│   ├── AccessDeniedException       (403 / type access_denied)
│   ├── NotFoundException           (404 / type not_found)
│   ├── ConflictException           (409 / type conflict)
│   ├── ValidationException         (422 / types validation_error, invalid_request)
│   ├── RateLimitException          (429 / type too_many_requests)
│   ├── ProviderException           (type provider_error)
│   ├── FileException               (file_* types)
│   └── ServerException             (5xx / server_error, internal_error)
├── TransportException              (network layer, reconnect failures)
├── ProtocolViolationException      (malformed events, unverifiable tool requests)
│   └── ProtocolVersionException
├── ReplayGapException              (missing range, unrecoverable gap)
├── UnsupportedCapabilityException
├── DefinitionsNotSynchronizedException
├── DefinitionConflictException
├── DefinitionDriftException
└── OperationCancelledException     (cancellation token fired mid-stream)
```

API errors are mapped from the `{error: {type, code?, message, details?}}` envelope by type, with a status-code fallback for unknown or unparseable responses. `RateLimitException` exposes the parsed `Retry-After` delay. Messages never embed credentials.

## Capability detection

`GET /capabilities` is positive discovery, not exhaustive. The `Capabilities` model exposes `protocolVersion`, `families`, `eventTypes`, `toolKinds` and `features`. Treat `event_types` as informational, never as a strict allowlist; unknown event types still decode as `UnknownEvent` and additive fields are preserved.

```php
$capabilities = $envai->capabilities()->getCapabilities();

if (in_array('client_tools', $capabilities->features, true)) {
    // client tool execution is advertised
}
```

Alpha caveats:

- approval: event models are supported for forward compatibility, but the automatic helpers stay disabled until the capability is explicitly usable; the approval endpoint is currently a stub (see the next section);
- steer: the low-level operation is exposed and the `turn.steered` event is decoded, but there is no promise that alpha actually modifies the LLM loop;
- restore: the helper is exposed, but an accepted restore is not a durability acknowledgement.

## Alpha limitations

These limitations apply to the bundled server contract snapshot; check capabilities and deployment-specific behavior before relying on optional features:

- Replay window: the in-memory replay window and live buffer are limited, there is a race between replay and subscribe, and the sequence resets after eviction or restart. There is no epoch and no `cursor_expired` signal. Gaps that cannot be recovered raise `ReplayGapException`; the SDK never promises durable or lossless replay, so applications must define their own recovery policy.
- Approval: `submitApproval()` maps the alpha stub behavior; the endpoint answers `409` until approval resolution is supported, which surfaces as a `ConflictException`.
- Steer: `steer()` records guidance (HTTP 202) and `turn.steered` events decode, but alpha does not guarantee the guidance changes the LLM loop.
- Restore: `restore()` is exposed but "accepted" does not mean durable; persistence is best-effort.

## Testing with fake

The Laravel testing helper `EnvaiFake` wires a real `EnvaiClient` to an in-memory sync state store plus scriptable remote fakes, with no HTTP and no database:

```php
use Digitalygo\Envai\Integration\Laravel\Testing\EnvaiFake;

$fake = EnvaiFake::make($client);        // or with custom RemoteReader/RemoteWriter fakes
$fake->ready();                          // preset the ready manifest from the local definitions
$fake->swap($this->app);                 // rebind the container

$definitions = $fake->definitions();     // DefinitionsService over the in-memory store
```

- `ready()` presets the ready manifest with the hash of the currently compiled definitions, so the readiness gate passes immediately. Without `ready()`, the connection is treated as never synchronized and the gate fails closed, which is the right default for tests that exercise the sync itself.
- `swap()` replaces the provider bindings (`envai.definitions`, `DefinitionsService::class`, `envai.client`, `envai`) on any container exposing `instance()`.
- `store()` exposes the `InMemorySyncStateStore`, `reader()` / `writer()` expose the remote fakes and `client()` returns the wrapped client.
- The default reader and writer are backed by the client's own services; pass fakes implementing `RemoteReader` and `RemoteWriter` to script the remote side without HTTP.

Core tests can use the in-memory stores directly: `InMemorySyncStateStore` for sync state and `InMemoryClientToolCallStore` for tool call deduplication, without Laravel at all.


## Development

From a source checkout:

```bash
composer install
composer validate --strict
composer check
composer audit
```

`composer check` runs formatting checks, static analysis and PHPUnit. Database
contract tests use the explicitly configured `ENVAI_TEST_MYSQL_*` and
`ENVAI_TEST_PGSQL_*` environments and otherwise skip. The automated suite uses
local fakes and fixtures; it does not need a production Envai API key. Some
transport tests start a loopback HTTP server.

## License

MIT. See [LICENSE](LICENSE). Bundled JSON Schema metaschemas retain their
[upstream notices and licenses](resources/json-schema/LICENSE).

## Managed wiki and provider usage

The wiki V1 client supports durable source upload/status/download, explicit inbox/outbox/sync operations, immediate withdrawal and revisioned catalog reads. It includes Ed25519 webhook verification and transactional PDO receipt + application outbox persistence. See [the integration guide](docs/WIKI.md). Provider usage is tracked with inactive billing; legacy cost fields are nullable when unknown.

See [chat attachments](docs/CHAT_ATTACHMENTS.md) for upload, conversion, retry and streaming with files. This surface is unreleased until the matching backend is deployed.
