# Envai PHP SDK documentation

Start with the [installation and quick start](../README.md#installation).
The SDK provides typed HTTP services, ATP v2 event streaming, declarative
resource synchronization, client-tool verification and optional Laravel
integration.

## Guides

- [Configuration and Laravel](../README.md#framework-independent-configuration)
- [Typed HTTP services](../README.md#http-client)
- [Local agent, tool and team definitions](../README.md#local-definitions)
- [Synchronization, drift and deployment](../README.md#deployment)
- [Workspace and chat creation](../README.md#workspace-and-chat-creation)
- [Streaming, cursors and turn helpers](../README.md#streaming)
- [Client-tool registration, signatures and durable state](../README.md#client-tools)
- [Error handling](../README.md#error-handling)
- [Runnable examples and recipes](EXAMPLES.md)
- [Compatibility, schema shapes and recovery](CURRENT_SERVER_COMPATIBILITY.md)

## Public contracts

[`openapi.yaml`](openapi.yaml) describes the HTTP operations and request/response
models. [`events.json`](events.json) describes ATP v3 envelopes and payloads; `events-v2.json` retains the immutable legacy schema.
Pass both files together to contract tooling so relative references resolve.
These files include infrastructure and provider callbacks for contract
completeness; their presence does not mean an application should invoke them.
Use the documented services for normal client operations and discover optional
features through `capabilities()`.

The [provenance manifest](../tests/fixtures/current-server/provenance.json)
records the exact upstream revision and content hashes of these two snapshots.
Locally generated regression fixtures are listed separately. The tests verify
the retained bytes without contacting a production service. Contract examples,
resource IDs and cryptographic test vectors are synthetic and are not
credentials or customer traffic.

## Security and compatibility

Use HTTPS for production endpoints and load API keys from a secret manager or
environment. Do not place secrets in definition files or source control. Signed
client-tool requests must be verified before execution; signatures do not
provide freshness or exactly-once external side effects. Replay is bounded and
best effort. Read the [safety guide](CURRENT_SERVER_COMPATIBILITY.md) before
using stateful or effectful integrations.

## Contributing

Run `composer check` and `composer audit` in a source checkout. Follow the
[development instructions](../README.md#development) for database contract-test
configuration. Preserve the root MIT license and the attribution/licenses of
bundled JSON Schema metaschemas.

Definition sync adoption records the actual remote definition. If it differs from the
manifest and overwrite was not requested, adoption succeeds without writes but does
not mark the manifest ready. Every real apply clears stale readiness before work;
dry runs remain read-only. An ambiguous resource blocks subsequent mutations until
its outcome is explicitly reconciled. Custom sync stores retaining a ready marker
must implement `InvalidatesReadyManifest` to allow another apply.

Before each remote definition mutation, sync durably writes an ambiguous intent.
Successful read-back/application replaces it with confirmed state. A process crash
or unknown response therefore cannot silently cause another mutation on restart.
The planner also blocks pruning ambiguous orphan resources. Definitive application
rejections restore the previous state; server/transport uncertainty keeps the intent.

Existing database sync installations require an explicit migration before adopting
mutation journaling: add nullable `TEXT state_metadata` to the resources table. On
MySQL, use `VARBINARY(764)` for `connection` and resource `local_key`, and
`VARBINARY(64)` for `resource_type` and lock `owner`. Apply the connection change to
resources, manifests and locks. The SDK rejects older case-insensitive identity
schemas instead of reading another account's state; it never alters existing columns
automatically. New explicit migrations/schema creation use exact byte identities,
including case, accents and trailing spaces. Preserve and inspect existing ambiguous
rows before migration; do not reset state to force retry.

A journaled operation can recover locally from authoritative readback matching both
its intended payload and the current desired definition, or confirmed absence for a
journaled deletion. Recovery issues no mutation. Differing/missing/duplicate reads
stay blocked. Lost agent/team create replies are reconciled by a unique exact-name
lookup, and recovered remote IDs feed subsequent team operations.
