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
