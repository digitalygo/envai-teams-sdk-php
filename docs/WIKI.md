# Wiki V1 integration

The public contract is `packages/contracts/openapi.yaml`; `wiki.json` describes public metadata and the discriminated webhook union. `fixtures/wiki` contains synthetic, cross-repository examples and the exact-byte Ed25519 signature vector. The PHP SDK is the V1 integration SDK.

## Document lifecycle

1. POST `/api/v1/workspaces/{workspace_id}/files/sources`, multipart field `file`, with a stable `Idempotency-Key`. The original is acquired durably before HTTP 202. The response contains `file_id`, `conversion.status=queued` and `indexing.status=pending_conversion`.
2. Poll GET `/files/sources/{file_id}`. A completed conversion moves the document to `inbox`; it does not index it, start a wiki run or emit `wiki.inbox.file.processed`.
3. Explicitly POST `/wiki/inbox/runs` with `webhook_url` and a new `Idempotency-Key`. Processing emits one logical `wiki.inbox.file.processed` only after the RAW and catalog transition is committed.
4. POST `/wiki/outbox/files` with `{file_id}` to withdraw immediately. Staging removes RAW visibility and references without starting a team, changing artifacts or emitting a wiki event. Conversion completion and delayed inbox retries cannot restore the withdrawn source.
5. Explicitly POST `/wiki/outbox/runs` to clean artifacts and delete the original and Markdown. `wiki.outbox.file.deleted` follows confirmation of both deletes.
6. POST `/wiki/syncs` runs inbox then outbox; a failed first phase does not skip the second. Outbox is snapshotted when its phase starts. New uploads after an inbox snapshot wait for a later run.

Paths above are relative to `/api/v1/workspaces/{workspace_id}` except where fully specified. All metadata responses use `{data: ...}`. Source status retains scoped tombstones. Original download at GET `/files/sources/{file_id}/download` checks current RAW visibility; it is not a public S3 URL.

Supported originals: MD/TXT, CSV, XLSX, PDF/DOCX via `mistral-ocr-latest`, MP3 and MP4 audio via direct ElevenLabs `scribe_v2`. MP4 has no frame analysis. The inclusive original limit is 1,000,000,000 bytes. There is no independent application duration limit. Provider limitations and incomplete conversion are explicit errors, never silent truncation. Stream large uploads from a local file; align proxy/PHP request-size and timeout settings with this limit.

## Queue, replay and status

A workspace has at most one active operation and 20 waiting operations. Inbox/outbox batch defaults are 25/10, captured in each operation and configurable server-side. The finite phase snapshot may span multiple batches; each delegation is foreground and sequential.

Operation starts require `Idempotency-Key`. Identical replay returns the original operation, even when the queue is full; a reused key with a different payload returns 409 `idempotency_conflict`. The recipient is not replaced by replay. Capacity rejection is 429 `wiki_queue_full`, with `Retry-After: 30`; this is a retry hint, not a reservation. At most three technical attempts are permitted; ambiguous paid provider submissions must be reconciled rather than blindly repeated.

GET `/wiki/operations/{operation_id}` reports `queued|running|completed|failed`, phase counts, per-file outcomes, captured batch sizes and the latest cost projection. A withdrawn inbox source counts as `withdrawn`, not a processing error. An empty operation succeeds without AI calls and still gets one terminal event. Already completed files remain valid when another file makes the operation fail.

## Catalog and managed storage

GET `/wiki/categories` returns category IDs, names, descriptions and the workspace catalog revision. GET `/wiki/categories/{category_id}/index` is deterministic and does not call AI. Choose `all=true` or inclusive `from` and `to` starting at 1; `type=raw|artifact` filters entries and omission includes both. Ordering is `inserted_at`, then `entry_id`. Counts and requested/actual ranges are explicit; an out-of-range page is empty, and a full read cannot silently truncate.

Pass `catalog_revision` on subsequent pages. Mutation returns 409 `catalog_revision_changed`; restart the read. A prior revision never authorizes reading a withdrawn RAW. GET remains the exhaustive path when probabilistic Decisions QUERY excludes an entry.

Artifact creation/editing, CAS, asynchronous reference requests, Decisions QUERY and inbox/outbox confirmation are internal actor-bound runtime capabilities in V1. They are not public writer APIs. Generic path-based files APIs cannot bypass protected managed wiki namespaces. Markdown authority remains in the existing FFS; application records are catalog/lifecycle metadata. Older retrieval context can produce new artifacts after withdrawal, but cannot read or reindex the withdrawn RAW.

## Costs are independent of functional outcomes

`billing.status` is `inactive`. `usage_cost` describes tracked provider consumption, never a customer price or charge. `status=complete` requires a closed scope with no unpriced invocation. Pending costs retain `totals=null`; `priced_subtotals` contains only the known portion. Amounts are decimal strings grouped by original currency. Never add different currencies, round prematurely, apply FX or change an unknown amount to zero.

`basis=reported|calculated|mixed|none` distinguishes provider reports from versioned-tariff calculations. A genuinely empty scope has `invocation_count=0`, `basis=none`, `totals=[]` and `priced_subtotals=[]`. Conversion costs belong to upload; inbox does not add them again. Sync unites phase invocations. Polling, HTTP replay and event redelivery are not additional AI calls.

`revision` and `as_of` identify a snapshot. A successful operation may have pending costs; failure may have positive costs. Late reconciliation changes GET usage metadata only. It does not change terminal event bytes, status, `finished_at` or event identity and does not emit a second terminal.

OpenAPI 2.1 explicitly makes legacy completion `cost_usd` and ATP `usage.cost_usd`, terminal `cost` and `chat_cost` nullable when their provider amount is unknown. Existing numeric values remain valid. Consumers must update float-only assumptions before deploying this contract. ATP retains envelope version 2; typed `billing` and `usage_cost` are additive, with canonical scope under `data.usage`. Billing remains outside V1.

## Signed outgoing webhooks

Common fields: `schema_version="1"`, `event_id`, `event_type`, `workspace_id`, `operation_id`, `occurred_at`.

- `wiki.inbox.file.processed`: `file_id`, monotonic `source_revision`, `canonical_raw_path`, `availability="raw"` at transition time.
- `wiki.outbox.file.deleted`: `file_id`, `source_revision`, nullable `previous_canonical_raw_path`, `lifecycle="deleted"`.
- `wiki.inbox|outbox|sync.completed|failed`: matching `status`, `phases`, `finished_at`, inactive `billing` and immutable `usage_cost` snapshot. Exactly one logical terminal per operation.

File events do not claim final operation cost. Delivery order is not guaranteed. A late `processed` must never reverse a later revision or a deleted tombstone. Before an application reads a source or runs a downstream workflow, fetch current source status and handle withdrawal/404. Downstream workflows have their own lifecycle and consumption.

Each attempt sends exactly one of each header:

- `Envai-Webhook-Id`: logical event ID
- `Envai-Webhook-Timestamp`: canonical positive decimal Unix seconds for this delivery attempt
- `Envai-Webhook-Key-Id`: trusted Ed25519 `kid`
- `Envai-Webhook-Signature`: unpadded canonical base64url of the detached 64-byte signature

The UTF-8 signing input is the following concatenation, with LF separators and no final LF:

```text
envai-webhook-v1\n{kid}\n{event_id}\n{timestamp}\n{lowercase_sha256_hex_of_exact_raw_body}
```

Never parse/reserialize the body before signature verification. Reject missing/duplicate/malformed signing headers, non-Ed25519 keys, invalid signature, wrong expected workspace and a delivery timestamp more than 300 seconds in the past or future. Resolve keys only from configured Envai origin `/.well-known/wiki-webhook-jwks.json`, never a payload URL. Cache public JWKS for 300 seconds and refresh once on unknown `kid`. The route publishes existing signing keys; it does not create credentials. Protocol domain separation prevents treating JWT/client-tool signatures as wiki signatures.

A retry retains the identical body/event ID but gets a fresh signed delivery timestamp, so old events can be delivered legitimately. The signature replay window does not replace durable event deduplication. HTTPS delivery validates every A/AAAA target and pins the resolved connection, blocks private/loopback/link-local/metadata targets and redirects, and repeats DNS validation on each attempt. Delivery retries never restart document work.

Commit a verified, scoped receipt and durable application work before acknowledging with 2xx. Deduplication key is connection + workspace + event ID; same ID with different payload is a collision. Do not perform remote side effects inside the HTTP receipt transaction. A generic process-local callback/claim cannot promise exactly-once external effects. The PHP transactional PDO inbox commits receipt and local outbox insertion together: a crash before commit leaves both absent and safely retryable; after commit a retry is a duplicate. Existing generic webhook manager integrations must reconcile historical abandoned claims before migration rather than assuming a time-based reclaim makes external effects safe.

The ElevenLabs callback is a separate inbound provider protocol with its own HMAC, keys and event contract. A transcript callback is not a wiki file-processed event.

## Rollout and verification

Deploy only after FFS identity/CAS/fencing, current-source read gates, durable operation recovery and invocation recording are enabled together. Rollback must preserve RAW tombstone restrictions. Existing Markdown must be inventoried and explicitly imported or quarantined; it is not automatically an uploaded source with an invented S3 original.

Fixtures are synthetic and do not prove a production provider account, quota or deployed FFS version. Test local/provider fakes and database races first. Provider smoke calls, credential provisioning, production migrations and deployment require separate authorization. No billing, settlement or retroactive customer charges are part of this implementation.

## PHP quick start

```php
use Digitalygo\Envai\Models\Wiki\CategoryIndexRequest;
use Digitalygo\Envai\Models\Wiki\WikiRunRequest;

$source = $client->workspaceFiles()->uploadSource(
    $workspaceId, '/private/input/report.pdf', $stableUploadKey,
    filename: 'report.pdf', mimeType: 'application/pdf',
);
$status = $client->workspaceFiles()->sourceStatus($workspaceId, $source->fileId);
$accepted = $client->wiki()->inbox(
    $workspaceId, new WikiRunRequest('https://your-app.example/webhooks/wiki'),
    $stableOperationKey,
);
$operation = $client->wiki()->operation($workspaceId, $accepted->operationId);
$categories = $client->wiki()->categories($workspaceId);
$index = $client->wiki()->categoryIndex(
    $workspaceId, $categoryId,
    new CategoryIndexRequest(from: 1, to: 25, type: 'raw', catalogRevision: $categories->catalogRevision),
);
$withdrawn = $client->wiki()->withdraw($workspaceId, $source->fileId);
```

`outbox()` and `sync()` use the same `WikiRunRequest` and explicit idempotency key. Mutations and upload are never retried automatically by the SDK. An ambiguous upload must reuse the same key and original bytes if the application deliberately replays it. Never generate a new key merely because HTTP timed out. `uploadSource()` keeps a filesystem-backed multipart part; the standard cURL transport streams it without loading 1 GB into PHP memory. The application must keep the file unchanged for the request and replay.

### Verify and durably accept

```php
use Digitalygo\Envai\Tools\Security\JwksResolver;
use Digitalygo\Envai\Webhooks\WebhookRequest;
use Digitalygo\Envai\Webhooks\Wiki\Pdo\PdoWikiWebhookInbox;
use Digitalygo\Envai\Webhooks\Wiki\WikiReceiptOutcome;
use Digitalygo\Envai\Webhooks\Wiki\WikiWebhookVerifier;

$keys = new JwksResolver($httpTransport, 'https://your-envai.example/.well-known/wiki-webhook-jwks.json');
$verifier = new WikiWebhookVerifier($keys, $expectedWorkspaceId);
$inbox = new PdoWikiWebhookInbox($pdo, $verifier);
$request = new WebhookRequest('POST', '/webhooks/wiki', $headers, $exactRawBody);
$outcome = $inbox->receive($request, 'production-envai', function (PDO $transaction, $event): void {
    $insert = $transaction->prepare('INSERT INTO application_outbox (event_id, payload) VALUES (?, ?)');
    $insert->execute([$event->eventId, $event->payload->toJson()]);
});
http_response_code($outcome === WikiReceiptOutcome::Collision ? 409 : 204);
```

Use transactional tables (InnoDB on MySQL). Create the receipt table using the included migration `2026_10_13_000000_create_envai_wiki_webhook_inbox.php` or its equivalent schema, and create your application outbox with an event ID uniqueness constraint. Publish/run migrations explicitly under your deployment process; package construction never creates tables. In Laravel, pass the intended connection's `getPdo()` outside any open transaction. The callback writes only to the provided PDO transaction; it must not commit, roll back, change connection, make network calls or execute application effects. Process durable jobs separately, with their own idempotency policy. Return non-2xx on verification/store failures. `Accepted` and `Duplicate` are the only positive acknowledgments.

The default webhook validator accepts at most 1 MiB per body. Pass a deliberately chosen `WebhookValidator(maxBodyBytes: ...)` to the inbox if large per-file outcome lists require more; keep an explicit bounded transport limit.

The generic `WebhookManager` also accepts `WikiWebhookVerifier`. Use it only with a durable store/dispatcher appropriate for your application's effects; its generic interface does not make separate receipt and side-effect operations atomic. `WikiWebhookEvent::fromPayload()` distinguishes per-file and terminal fields; per-file `billing`/`usageCost` are null. Preserve source revision tombstones in your application, and reread current source state before applying downstream work.

### Compatibility

Existing completion/ATP constructors retain their positional arguments. New optional typed `billing`/`usageCost` fields are appended. Legacy `costUsd`, `cost` and `chatCost` are now nullable; absence/unknown is not coerced to zero. Old fixtures without the new objects still decode. Unknown fields are retained. Decimal strings and original provider currencies are never converted by PHP. Refresh cost projections through the source/operation GET or existing completion metadata after a pending terminal snapshot.

## Durable terminal metadata on completion GET

The existing owner/workspace/chat-scoped completion GET may include `terminal_snapshot`: an immutable object with `event_id`, `occurred_at`, `event_type` (`turn.completed`, `turn.cancelled` or `turn.failed`) and `data`. Legacy records may omit it or return null. The data is terminal metadata only: `turn_id`, usage/cost, optional chat lifetime totals (`chat_usage`, `chat_cost`) and the applicable finish reason, cancellation reason or sanitized failure code/message. The chat totals use ATP usage fields with nullable USD costs and are frozen before the journal write, like the rest of the terminal data. Older crash-recovered snapshots may omit chat totals or have a smaller metadata set. The snapshot is not an ATP replay envelope and does not contain or recover assistant response text, input text or streamed deltas.

Late provider-cost reconciliation can advance the completion's current `usage_cost.revision` and `cost_usd` without altering snapshot identity, occurrence time or data. Read current costs from the completion, not from the immutable historical snapshot. For a tracked scope, legacy `cost_usd` is populated only for a complete USD-only total, or zero for a closed scope with no invocations; pending and non-USD/mixed-currency totals remain null. Historical records without a tracked scope preserve their existing legacy value. No currency conversion or customer charge is implied.

The PHP `Completion::$terminalSnapshot` property is an optional `CompletionTerminalSnapshot` with typed identity, event type and time plus a wire-faithful `JsonObject` metadata payload. Existing positional constructor parameters are unchanged. Missing/null snapshots hydrate to null; a snapshot with a different `data.turn_id` is rejected. Server-side ownership remains authoritative; the SDK uses the existing workspace/chat/completion URL and bearer authentication.

## Source conversion diagnostics

Source metadata may include `conversion.error_code` (sanitized machine code or null), `conversion.recoverable` (boolean) and `conversion.next_retry_at` (timestamp or null). `recoverable` is true only while an automatic technical recovery is durably scheduled and currently eligible, with attempts remaining and a source that has not completed or been withdrawn. The timestamp exposes that actual retry schedule. Initial queued work, healthy running, completed or withdrawn sources, terminal failures and exhausted retries report false/null. These fields do not promise operator repair or reveal raw provider responses. Older responses may omit the diagnostics. Example error codes include `provider_limit_exceeded`, `audio_track_missing` and `ffs_document_resource_limit`; branch on stable codes rather than free-form provider messages. A failed conversion never supplies a successful placeholder document.
