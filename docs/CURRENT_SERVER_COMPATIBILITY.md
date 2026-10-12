# Current Envai server compatibility

This update targets `digitalygo/envai-teams` commit
`55275860c9a950eaad8e39e9d2b728149084779c` (ATP v2). `openapi.yaml`
and `events.json` are byte-for-byte snapshots of that commit. The 52-operation
inventory is unchanged. Shared deterministic fixtures live in
`tests/fixtures/current-server`. The provenance manifest separates exact server
`sources` from locally `generated_fixtures`. The foundation document is also
included as an exact source snapshot. Each source records its repository path,
local snapshot path, SHA-256, Git blob ID and byte count; tests check every source
and every generated fixture. These offline checks establish snapshot integrity,
not freshness against later server commits. Generated examples and signatures
are test data, not server traffic or server-issued signatures.

## Composer name and rollout

The repository is now `digitalygo/envai-teams-sdk-php` and this branch changes the
Composer identity from `digitalygo/atp-php-sdk` to
`digitalygo/envai-teams-sdk-php`. PHP namespaces and Laravel class names remain
unchanged. No tag, package publication, merge or deployment is part of this PR.

1. Review and merge the SDK change, then have a maintainer create the real
   new-name release through the normal release process.
2. Only once that release exists, resolve the new package in each consumer,
   update its lockfile, and run installation/application checks.
3. Review each consumer PR independently before deployment. Laravel
   `dont-discover` entries and explicit package/vendor-path checks also need the
   new Composer name; namespaces do not.

Old tags remain unchanged, but direct-VCS Composer package discovery uses the
repository's default-branch name. Retaining old tags alone is not a guarantee
that an old-name `composer update` will keep resolving after the rename reaches
main. Existing locked installs and fresh dependency resolution are different.
Do not remove old release artifacts or repurpose the old repository name.

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
local JSON Schema before invoking a handler. Verified arguments are copied from
their canonical JSON representation before schema validation or execution, so
handlers receive the exact binary64 numeric semantics covered by the signature,
including when PHP could represent a larger integer exactly. Encode identifiers
or precision-sensitive integers/decimals as strings when exact precision is
required. The validator supports JSON Schema
Draft 6, 7, 2019-09 and 2020-12 through `opis/json-schema`. It preserves `{}` versus
`[]` until validation, then passes native arrays to handlers as before. External
schema references are not fetched, and custom filters/mappers/default mutation
are disabled. Invalid or unresolved schemas fail closed.

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
events from the trusted authenticated stream for the intended chat. Server
protocol support would be needed for stronger cryptographic context binding.

PHP handlers are synchronous. The timeout check is cooperative after the
handler returns; it cannot interrupt a blocked handler or roll back side effects.
Configure application I/O deadlines and idempotency for effectful tools. The
stream handshake timeout is also observed between transport reads. Replay is a
resident-memory window, not durable replay; a clean EOF ends stream iteration.
No production API calls are needed by the automated suite.

The package continues to use the existing MIT license and Digitaly Go copyright.
