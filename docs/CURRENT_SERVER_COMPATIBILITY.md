# Compatibility and safety

This guide describes the public PHP SDK behavior for the bundled ATP v2
contract snapshot. It is a compatibility reference, not a guarantee that every
optional feature is enabled on every Envai deployment.

## Contract snapshots

[`openapi.yaml`](openapi.yaml) and [`events.json`](events.json) are exact
snapshots covering 52 HTTP operations and ATP v2 event shapes. Their upstream
revision, source paths, SHA-256 hashes, Git blob IDs and byte counts are recorded
in [`provenance.json`](../tests/fixtures/current-server/provenance.json).
Only these two machine-readable contracts are declared as source snapshots.

The manifest separately records locally generated fixtures. Their events and
signatures are deterministic test data, not captured customer traffic or
server-issued credentials. Offline tests verify every retained source and
fixture; they establish integrity, not freshness against a newer deployment.

## Upgrading from 0.1

Version 0.2.0 uses Composer package `digitalygo/envai-teams-sdk-php` instead of
`digitalygo/atp-php-sdk`. PHP namespaces and Laravel provider class names are
unchanged. Add the public VCS repository as described in the
[installation guide](../README.md#installation), replace the old Composer
requirement, update the lockfile, and run application checks before deploying.

Also update any Laravel `dont-discover` entry and hard-coded vendor path that
uses the old Composer name. Existing lockfile installs and fresh dependency
resolution are different: test both when migrating. Keep the old lockfile
available for application rollback; do not hand-edit its source references.

The artifact-download method requires the explicit call-site migration below.
No existing release tag needs to be changed to migrate an application.

## Capability discovery

Use `$envai->capabilities()->getCapabilities()` for the server's advertised
protocol version, event types and optional features. The event list is positive
discovery, not an allowlist for the parser: valid unknown or additive event data
must remain consumable. A method or event class in the SDK does not prove that
its feature is enabled on the server.

For the bundled snapshot, approval can return `409`, steering acceptance does
not guarantee that the guidance changes model execution, and restore acceptance
is not a durable-persistence acknowledgement. Handle these outcomes explicitly.

## Artifact download migration

The current server no longer accepts a storage `path`. It returns opaque
`generation` (`igr_...`) and `artifact` (`art_...`) IDs in each file's signed
`download_url`. Use the new method:

```php
$result = $envai->images()->downloadGenerationArtifact(
    generation: $generationId,
    artifact: $artifactId,
    signature: $signature,
    expires: $expires,
    sink: $sink,
);
```

Copy these values from the server-issued URL. Never invent a signature or
recompute expiry. The helper reproduces Laravel's sorted signed query order and
adds the signature last. It sends no bearer token and does not retry a download.
The legacy `downloadArtifact(path, signature, expires, sink)` signature remains
available for older servers, is deprecated, and is incompatible with this
current server. Switch call sites explicitly; passing a path to the old method
cannot be made compatible automatically.

Generation acceptance now exposes `expiresAt`. A current generation expires two
hours after creation. Image uploads are staged for two hours and bind to exactly
one generation. Do not reuse an upload for another generation. Status `files`
remain `JsonObject` values for source compatibility; current entries carry
`artifact_id`, `mime_type`, `size`, and `download_url`, not storage disk/path or
public URL. Expired artifacts and unknown generations can return generic 404s.

## Additive model and event data

- `LlmModelEntry` now exposes the upstream display name, description, canonical
  slug, Hugging Face ID, context/completion limits, architecture, supported/default
  parameters, reasoning, knowledge cutoff, upstream expiration, alias target,
  moderation flag, and sync time. Pricing is an informational `JsonObject`:
  decimal strings and nested overrides are preserved without float conversion.
  Billing remains based on server usage, not model catalog prices.
- Compaction events expose optional turn, reason, context/window/threshold
  telemetry. Missing telemetry remains valid.
- Usage exposes optional `spriteRamGbMs` and `toolCalls`.
- Unknown event types and additive fields remain accessible. Known replayable
  event types require a non-null envelope `turn_id`. The legacy decoder treats
  identifier strings as opaque rather than enforcing the complete ULID lexical
  grammar; it is a typed compatibility decoder, not a complete JSON Schema
  validator for every envelope field.
- HTTP 503 `gateway_draining` is surfaced through `ServerException`, with the
  server error code retained. Message submission is not automatically retried.

Existing constructor positions and namespaces remain unchanged. New optional
DTO fields follow the existing `extra` argument. Older accepted-generation
responses without expiry still hydrate; current-server responses provide it.

## Runtime safeguards

Turn helpers do not send after EOF or timeout without a live-confirmation ping.
Historical terminal events are consumed as replay while waiting for that ping.
Already buffered frames are drained before another blocking read, including
when the ping and turn events arrive in one network chunk.

Client tools verify the Ed25519 signature and JWK algorithm, require the supplied
chat to match the event chat, claim the call in the store, and validate the full
local JSON Schema before invoking a handler. The schema itself is eagerly
validated against bundled official draft metaschemas, even for unused optional
properties, definitions, combinators and conditional branches. Recognized local
references are resolved before execution, including referenced targets stored
under extension keys; unresolved external references fail closed. Ordinary
unknown extensions and literal `const`/`default` values remain data. Verified arguments are copied from
their canonical JSON representation before schema validation or execution, so
handlers receive the exact binary64 numeric semantics covered by the signature,
including when PHP could represent a larger integer exactly. Encode identifiers
or precision-sensitive integers/decimals as strings when exact precision is
required. The validator supports JSON Schema
Draft 6, 7, 2019-09 and 2020-12 through `opis/json-schema`; absent `$schema`
defaults to 2020-12. Each document must use one dialect: mixed-dialect embedded
resources and custom dialects are currently unsupported and fail closed. The official
metaschema snapshots and their source hashes/licenses are in
`resources/json-schema`. Unsupported custom dialects, malformed non-JSON values
and invalid schemas fail closed. It preserves `{}` versus `[]` until validation,
then passes native arrays to handlers as before. External
schema references are not fetched, and custom filters/mappers/default mutation
are disabled. Invalid or unresolved schemas fail closed.
Only schema locations contribute resource IDs and anchors; IDs inside inert
literal/extension data cannot satisfy references. JSON Pointers may explicitly
promote such values to schemas, at which point they are fully checked. Finite
productive recursion is supported. If evaluation revisits the same schema and
instance location without descending, the entire validation fails closed with
`invalid_arguments`, avoiding an unbounded reference loop. This conservative
rule also applies when an `anyOf` alternative has already matched but the
validator evaluates another cyclic branch for annotation bookkeeping.

PHP schema/map positions (including `dependentRequired`, `dependentSchemas`,
and Draft 6/7 `dependencies`) accept associative arrays; empty schema/map arrays
are objects. Dependency property-name lists and schema lists stay lists.
Literal values under `const`, `enum`, `default` and `examples` are normalized
recursively without treating their member names as schema keywords. In literal
positions, `[]` means an empty JSON array. Use `new \stdClass`, explicit nested
`JsonObject`/`JsonArray` values, or `json_decode($json, false)` to preserve empty
objects or numeric-key objects unambiguously. `JsonObject::fromJson()` retains
its historical associative decoding and is not a shape-preserving constructor
for nested empty objects. A nonempty PHP list cannot stand in for a schema;
malformed shapes still fail closed before handler effects.

The same normalization is now used for definition compilation and outbound tool
create/update schemas. Valid schemas using native PHP maps, nested `stdClass`
values (`json_decode(..., false)`) or explicit JSON wrappers survive manifest
hashing, sync planning, file/PDO snapshot roundtrips, HTTP requests and `UserTool`
responses without changing empty/numeric-key objects into arrays. Invalid local
schemas produce a `tool.invalid_schema` compilation violation. The public root
`parameters` property remains an array for compatibility; nested ambiguous
objects use `JsonObject`. `JsonObject` now serializes as a JSON object even when
empty or numerically keyed. Legacy sync snapshots that already lost shape cannot
be repaired by inference; recompile/resynchronize their definitions.


The gateway rejects an empty tool output string even though the OpenAPI string
schema has no `minLength`. The runner converts an empty handler result to a
controlled `empty_output` error; low-level tool-output submission keeps server
validation authoritative.

Existing empty, whitespace-only or invalid JSON state files fail closed; they
are not silently treated as new stores. Every persisted call row is checked
before an operation reads or rewrites the snapshot, including unrelated rows.
A null row, invalid identity/status/metadata or mismatched deduplication key
raises `FileException` without rewriting the file or re-executing a handler.
Missing optional fields in legacy format-1 records remain supported. Keep a separate persistent client-tool
store for every server/account identity. Do not discard it while replay can
occur. The signature covers call ID, tool name and canonical arguments; it does
not authenticate envelope chat/turn/timestamp or prove freshness. Only process
events from the trusted authenticated stream for the intended chat. Do not use a signature alone to authorize a different chat, account or action.

PHP handlers are synchronous. The timeout check is cooperative after the
handler returns; it cannot interrupt a blocked handler or roll back side effects.
Configure application I/O deadlines and idempotency for effectful tools. The
stream handshake timeout is also observed between transport reads. Replay is a
resident-memory window, not durable replay; a clean EOF ends stream iteration.
The automated suite uses fakes and local fixtures; production API calls are not needed.

## Recovery checklist

- On an ambiguous mutation, inspect the remote state before deciding whether to retry.
- On `ReplayGapException` or a reset replay window, reconcile application state; do not assume missed events can be recovered. A clean EOF ends iteration and does not imply a completed turn.
- Preserve call-store records across restarts and across retries. A call marked ambiguous needs reconciliation, not automatic re-execution.
- Keep effectful handlers idempotent with a domain-level operation key. A crash between the external effect and its durable acknowledgement remains ambiguous.
- Keep file stores outside a public web root and restrict filesystem access. Use shared atomic storage when multiple hosts can process the same calls.
- Avoid logging API keys, signed download URLs, tool arguments or sensitive outputs. The SDK cannot sanitize arbitrary logs written by application code.

The package uses the MIT license. Bundled JSON Schema metaschemas retain their
upstream attribution and licenses in `resources/json-schema`.
