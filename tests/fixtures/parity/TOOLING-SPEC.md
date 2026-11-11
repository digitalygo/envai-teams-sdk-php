# Portable definitions and reconciliation parity, version 1

Status: implementation acceptance specification, derived from PHP SDK `f4117c35fa75e93709ce36cbe0aca86727ece9b3` Definitions/Sync sources and tests. `golden.json` was executed against that implementation with fake remotes and a read-only repository mount. These are synthetic SDK fixtures, not a new server API contract. No provider calls occur.

## Required portable capabilities

All four SDKs need (a) local definitions compilation and validation, (b) canonical manifest identity, (c) three-way plan/diff, (d) safe explicit apply/reconciliation, (e) durable namespaced resource mapping with locks, (f) readiness/identity lookup, and (g) CLI validate/plan/sync/status/state. Existing PHP capabilities must remain. Native PHP team builders/classes and Artisan/Laravel container syntax are language/framework adapters, not formats other languages must execute. Python/Go/TypeScript may expose idiomatic builders and native handler registration, but must support equivalent data and behavior.

Portable import surface: a JSON document whose `format_version` is the string `"1"`, with `tools`, `agents`, and `teams` OBJECT maps keyed by stable local identities. Values use normalized remote-shaped payloads; team `orchestrator_id` and `members[].agent_definition_id` are LOCAL agent keys until apply. Handler implementation/class/function references are separate process-local registration and never enter this document, a state file, the wire payload, or its hash. A JSON manifest is a safe interoperable input; provide Markdown+YAML-frontmatter agent compilation equivalent to PHP as well. Team source builders can be language-native or JSON/YAML; accepting only in-memory maps is insufficient CLI parity.

## Model and canonicalization

- Tool key equals immutable remote name. Payload: `name`, `kind:"client"`, `description`, object-root `parameters`, `timeout_seconds` (PHP registration clamps to 1..600, default120; golden uses30). Object/array shape inside arbitrary JSON-schema literals must survive untouched; do not recursively coerce `enum`, `const`, or schema data.
- Agent stable local key is separate from mutable remote `name`. Required `name`, nonempty `system_prompt`, `mode:"primary"|"subagent"`, booleans `can_write_knowledge`, `can_write_skills`; optional `description`, `model`, `tools_enabled:[{name}]`, `external_tools_enabled:[{name}]`, `permissions` object, `config` object. Markdown CRLF normalizes to LF. Preserve explicit null, absent, empty array and empty object for manifest identity. Reject `max_tokens` at any depth in config.
- Team payload: `name`, local-key `orchestrator_id`, ordered `members:[{agent_definition_id:local_key,role:string|null}]`, optional presence-tracked `description`. Preserve member order. All referenced agents must exist; orchestrator primary, members distinct subagents, orchestrator excluded from members. Validate remote-name uniqueness per resource type and client-tool references.
- Local `validate` is pure: no remote calls, no locks/writes. Plan/apply additionally obtain available model catalog and fail closed if unavailable; validate explicitly supplied agent models against it.
- Canonical manifest document is `{format_version:"1",tools:{...},agents:{...},teams:{...}}`. Sort/deduplicate agent tool-name sets as `[{name}]`; preserve team order; remote IDs, filepaths, handlers and credentials never enter it. RFC8785/JCS serialize (UTF-16 object-key ordering, existing tool-signature canonicalizers can be reused), SHA256 UTF8, prefix `sha256:`. Single-resource hash uses the same document with only that resource and other maps `{}`. Golden hashes must match exactly.
- Comparison normalization differs from hashing: strip server `id,user_id,created_at,updated_at`; for agents normalize optional missing/null/empty fields consistently with PHP `PayloadNormalizer`; map remote team agent IDs back to local keys; remove member enrichment `name,position`; preserve arbitrary tool-schema literal shapes. Do not conflate normalized comparison and original manifest identity.

## State and identity

Store key: stable non-secret account/deployment connection scope + resource type + local key. Scope MUST isolate different accounts/origins even if credentials rotate; credentials themselves are never scope IDs. Resource record: remote ID, remote name, desired hash, last-applied normalized payload, opaque remote marker (`updated_at` in current server), status `applied|drifted|conflicted|missing|ambiguous`, timestamp; optional store metadata preserved. Ready record: manifest hash and timestamp per scope. No SSE cursors/tool call claims in this store.

Tools address mutations by remote NAME; agents/teams by stored remote ID. Renaming agent/team local desired remote name must keep stored ID, not adopt another matching name. Tool name changes mean create new + orphan old; old may be pruned only explicitly. New team requests substitute actual agent IDs returned by same apply or stored prior mapping.

Interface: acquire/release exclusive scope lock, get/list/save/delete resource, get/mark ready manifest. File store: private permissions where supported, atomic durable replace, lock held separately from data file, corrupt/unknown formats fail closed, no automatic recreation. Memory store explicit ephemeral/testing. Multi-host parity requires a transactional database-backed implementation (existing PostgreSQL dependencies are a practical first implementation); do not advertise a local file as distributed coordination. PHP also ships SQLite/MySQL adapters; if peers lack a supported engine, list that support difference explicitly rather than calling it a language necessity.

## Plan and policy

Compare desired D, stored last applied A and observed remote R. Classifications: `create`, `unchanged`, `update`, `drift`, `conflict`, `adoption_required`, `missing_remote`, `orphan_managed`. See executable17case golden table.

Defaults fail closed for drift, conflict, missing and unmanaged same-name collision; pruning disabled. Explicit options `adopt`, `overwrite_drift`, `recreate_missing`, `prune`, `fail_on_drift`, `dry_run`, scope `all|tools|agents|teams`.

- D=A=R unchanged; D!=A and R=A update; D=A and R!=A drift; D!=A and R!=A conflict. **Conflict blocks even with all overrides.**
- Unmanaged name collision blocks unless adopt. Adoption records actual remote as last applied, not desired; adopt alone does not mutate remote. With overwrite option update can follow within same apply. PHP's current report marks successful adoption ready; later planning can propose desired change. Preserve this explicitly or coordinate any safety improvement across all four rather than silently changing one SDK.
- Managed missing blocks unless recreate. Orphan skips unless prune; only that scope's managed resources are eligible. Never delete unmanaged names discovered remotely.
- Remote metadata is read-only plan input; plan includes operation ID, type/local key, classification, action, blocking/reason, remote ID/name/marker, destructive flag, and leaf path/kind diff. Plan hash deterministic over content+options (not wall-clock timestamp).
- Highlevel `plan` holds scope lock through compile/catalog/remote read/plan and releases before returning. `apply` replans while holding one scope lock through all writes/state/readiness. Do not expose stale stored plan as permission to blindly apply later without revalidation.

## Apply, ambiguity, and readiness

Upserts order tools -> agents -> teams. Prune after upserts, teams -> agents -> tools. Preserve explicit dependency rejection (e.g. team still has chats); never bypass it. Each successful operation durably saves identity and actual applied payload before later operations. Dry run makes no mutation/state/readiness write. Partial scope never marks overall manifest ready.

Mutation transport loss: exactly one attempt, never automatic resend. Read authoritative remote once through existing authenticated client; equal normalized actual and intended payload means landed; delete+404 means succeeded; missing/different/failed readback means ambiguous and stop. Creation may require name lookup when ID was lost. Preserve API error envelope in report. An interrupted apply preserves earlier successful state: **no invented cross-resource server rollback**. Remaining operations skipped. No successful readiness marker after blocked/failed/ambiguous apply.

`require_synchronized` and typed `team/agent/tool` lookups compare current compiled manifest identity with ready marker. Lookup never performs surprise network sync. Optional lazy gate: modes deploy/manual/off do no automatic apply; lazy compares hash+TTL then locks/rechecks/applies before allowing action, propagates errors. No sync, schema creation or streams at import/startup/container registration.

## CLI

Commands `definitions:validate`, `definitions:plan`, `definitions:sync`, `definitions:status`, `definitions:state` or idiomatically equivalent subcommands with identical capabilities. Environment selectors ENVAI_BASE_URL/API_KEY/CONNECTION/DEFINITIONS_PATH/STATE_DRIVER/STATE_PATH; CLI must not print credentials. Flags: connection,path,store,dry-run,json,only,adopt,overwrite-drift,recreate-missing,prune,fail-on-drift. Validate requires only local input, no credentials/network ideally (PHP current bootstrap may differ; avoid adding a needless requirement). Plan and status never mutate remote. Machine-readable format version1, explicit status/outcomes/ready flag. Drift exit2; ordinary errors nonzero; no override inferred in noninteractive execution.

## Acceptance scenario IDs

TOOLING-01 valid Markdown/JSON/native builders compile same manifest; absent/null/[]/{} cases preserved.
TOOLING-02 local validation rejects badformat, emptybody, duplicate names, invalidmode/model(ifcatalog), unresolvedtools/agents, primary member/subagent orchestrator, max_tokens recursively.
TOOLING-03 JCS/hash golden equality incl toolsets sorted, teamorder significant, Unicode/literal schema preservation.
TOOLING-04 all17golden plan/reconcile cases and deterministic operation/report identities.
TOOLING-05 tool->agent->team create order and actual returnedID substitution; rerun unchanged writes0.
TOOLING-06 rename agent/team keepsstoredID; toolrename createsnew and onlyprunesold with flag.
TOOLING-07 adoption nooverwrite writes0/importsactual; adopt+overwrite updatesonce; conflict blocks allflags.
TOOLING-08 managed-only prune reverse order, dependency failure surfaced, unmanaged resources untouched.
TOOLING-09 timeout-after-landed readbackequal success, timeout-before/different/unknown staysambiguous, no secondmutation; deletegone success.
TOOLING-10 partialfailure preserves earliersuccess, stops furtherwrites, no ready; no fake rollback.
TOOLING-11 lockreleased onsuccess/failure; concurrent processes serialized; crash/corruptfile cannot silently reset; accountscopes isolated; credentialrotation stable.
TOOLING-12 durable file+DB roundtrip/restart, objectliteral shape, namespace and schema lifecycle; DBrollback leaves nostate on failed transaction.
TOOLING-13 readiness noimplicitnetwork; partial/dryrun notready; catalogunavailable failclosed.
TOOLING-14 CLI offline validate, plan JSON, dryrun0writes, drift exit2, status/state redaction, explicit destructiveflags.
TOOLING-15 nativeSDK integration using existing typed/authenticated CRUD, no bypass of retries/auth/errorhandling; examples against mockremotes.

## Porting split

Stage A normalized manifest/compiler+hash+validation+plan (pure modules); Stage B stores+reconciler+client adapters; Stage C facade/CLI/lazy gate+docs/integration tests. All stages are needed before marking portable tooling parity PASS. Runtime SDK owners can work in new cohesive files without touching their existing transport/toolrunner modules beyond a narrow facade registration.
