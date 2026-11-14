# Contract 3 migration

The PHP SDK selects contract **3** by default. Nullable provider costs are an explicitly versioned breaking change: unknown, pending and non-USD legacy projections remain `null`, never invented zero. Provider usage is not customer billing; `billing.status` remains `inactive`.

```php
$client = new EnvaiClient(new EnvaiConfig(
    baseUrl: 'https://envai.example/api/v1',
    apiKey: new SensitiveString($apiKey),
    contractVersion: 3, // default
));
```

## Current alpha source snapshot

This unreleased source is pinned to backend commit
`7133cae3042873073e3897f6733aa55314feca0d`. The bundled contract lists 147 server
operations, while `OperationInventory` keeps its explicit 78-operation SDK
subset (75 callable project-key operations; three API-key management methods
fail locally). The inventory excludes the backend image-notification receiver,
which is not a consumer SDK method. This source does not imply a new tag,
Packagist version, deployment or live paid turn.

## Safe negotiation

ATP uses `/api/v1/atp/v3/capabilities` and `/api/v1/atp/v3/chats/{chat_id}/...`, with `X-Envai-Contract-Version: 3`. Completion GET/list and every public wiki method use the same selector. Every successful negotiated response must acknowledge the exact selected version before hydration or stream consumption.

Before ATP/wiki mutations, the SDK verifies acknowledged capabilities with `protocol_version=3`, `contract_version=3` and integer `supported_contract_versions` containing 3. A successful check is cached per client. The v3 mutation namespace protects an old-server race after preflight: it cannot accidentally reach an old unversioned handler that ignores headers.

Missing/mismatched/duplicate acknowledgment, unsupported capabilities, HTTP 426 and an unavailable v3 route raise `ContractCompatibilityException`. The SDK never silently downgrades or resubmits an ambiguously accepted mutation. Other existing retry/idempotency rules remain in effect. Unrelated CRUD and public JWKS keep their existing requests without negotiation in default mode. The low-level `transport()` accessor still exposes the explicitly supplied raw transport; use client services for negotiation guarantees.

Deploy the v3 backend routes and edge routing before sending new SDK work. Old servers fail compatibility checks rather than receiving new-contract turns. Old clients can read compatible historical records on the upgraded backend; new work requires an updated SDK. The backend rejects an incompatible legacy completion page or replay tail atomically with HTTP 426 and `contract_upgrade_required`.

## Explicit legacy read-only mode

```php
$legacy = new EnvaiClient(new EnvaiConfig(
    baseUrl: 'https://envai.example',
    apiKey: new SensitiveString($apiKey),
    contractVersion: 2,
    maxLegacyReplayBytes: 67_108_864, // 64 MiB default; positive integer
));

$capabilities = $legacy->capabilities()->getCapabilitiesLegacy();
foreach ($legacy->chat($workspaceId, $chatId)->events($after, $cancellationToken) as $event) {
    // Finite historical replay, not a live subscription.
}
```

Legacy mode rejects **every non-GET request** before network activity, including unrelated CRUD. Wiki methods require contract 3. Explicit legacy discovery/replay methods reject a default-v3 client instead of silently hydrating a v3 response as legacy.

Legacy replay is fully buffered and validated before any raw bytes or typed event are delivered. Validation checks the original frozen v2 schema, requested chat identity and contiguous sequence after the supplied cursor. Missing numeric fields, malformed identifiers, invalid late events, foreign chats, gaps and v3 tails fail without exposing an earlier prefix. A configurable byte limit produces an explicit error instead of truncation. Cancellation is checked throughout capture and delivery; the network connection closes after capture or failure. EOF ends the finite snapshot without live reconnect. Pass `streamTimeoutSeconds` when an application needs an overall network deadline.

Legacy completion pages are likewise checked before hydration. Totals must be finite nonnegative numbers, and reserved cost fields nested under usage cannot be null, negative, strings or infinite. A malformed row rejects the whole selected page.

Laravel connections accept integer `contract_version` (default 3) and `max_legacy_replay_bytes` (default 67108864).

## Per-turn model selection

Ordinary chat messages accept an optional final `model` argument in
`ChatMessageRequest`, `ChatRuntime::sendMessage()`, `sendAndStream()` and
`sendAndWait()`. It is an exact, case-sensitive catalog `id`; the SDK does not
trim, alias, look it up or replace it. `null` omits it and the next send omits
it unless supplied again.

An explicit `model` pins the root for that turn with `strict_model` omitted,
`false` or `true`. `strict_model: true` without `model` remains a valid request
for the server-configured root policy; it is not an SDK-invalid pair. Ordinary
message posts need no native extension or `strict_model` capability. Other
native routes and fields keep their gates, and `nativeTasks` remains opt-in.

Ordered attachments and stable idempotency keys are unchanged. Model policy
failures are explicit: `422` `invalid_model`, `unknown_model`,
`model_unavailable`, `model_incompatible` or `strict_model_unavailable`, `409`
idempotency conflict, and `503` `model_catalog_unavailable`. None is
automatically resubmitted.

`LlmModelEntry::$selectable` is optional advisory metadata, not permission or a
client allowlist; `false` and absence are distinct.

## Additive artifact and wiki metadata

`AgentArtifactEvent` exposes optional `path`, `generationId` and `expiresAt`
strings after `extra`. They are data only: no download, storage, URL resolution,
fetch or rendering is implied. Unknown fields stay in `extra`, and malformed
known metadata is a protocol violation.

Managed workspace directory responses may include `wiki_sources` and `wiki`,
including on child entries. The existing `extra` map preserves them. The
contract adds no `read_only` field, and no generic managed-wiki write barrier is
relaxed.

## Events and immutable terminal metadata

`AtpEventDecoder(3)` accepts current v3 and unchanged schema-valid historical v2 envelopes. `AtpEventDecoder(2)` accepts only the immutable numeric v2 contract. All five cost-bearing event types validate version-appropriate costs; missing, string, negative and nonfinite costs are invalid. Unknown v3 cost is explicitly null. Tool signing preimages are unchanged.

V3 replay remains incremental, preserves original versions/IDs/sequence/data and retains contract selection across gap recovery. No historical event is relabeled. The frozen v2 schema is shipped as a runtime resource as well as a byte-exact documentation mirror.

Optional `CompletionTerminalSnapshot::$v` and `$acceptedProtocolVersion` retain envelope version and accepted-turn provenance; old missing fields remain supported. A recovery snapshot can be v3 for a turn originally accepted under v2. Late cost reconciliation changes current GET usage, not the committed terminal snapshot. Snapshot metadata is not response-text replay.

See [Wiki APIs and signatures](WIKI.md) and [executed validation and limits](WIKI_VALIDATION.md). Companion backend integration is [Envai Teams #45](https://github.com/digitalygo/envai-teams/pull/45); managed filesystem changes are [FFS #9](https://github.com/digitalygo/fake-filesystem-for-agents/pull/9).

## Chat attachments and deferred Wiki outcomes

Update to the matching backend before enabling attachment sends. Contract 3 remains selected, and `chat_attachments` is an additional capability requirement. Preserve message idempotency keys and ordered IDs; verify acceptance. Handle the new `deferred` Wiki file outcome. See [attachment integration](CHAT_ATTACHMENTS.md).

## Native task extension

`EnvaiConfig(nativeTasks: true)` negotiates `X-Envai-Extensions: native-tasks-v1` on execution requests and SSE. The default remains disabled. See [typed APIs, job waiters, signed tools and replay guarantees](BACKGROUND_TASKS.md) and the byte-exact [native contract](smidja-native.json).
