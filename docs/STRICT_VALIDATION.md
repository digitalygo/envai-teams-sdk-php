# Strict HTTP validation

Set `new EnvaiConfig(..., strictValidation: true)` to validate canonical HTTP
path, query and header parameters along with JSON or multipart request bodies
before admission or capability preflight. Successful JSON responses are validated
as complete wire envelopes before DTO hydration. Constraint violations throw
`InvalidArgumentException` for requests and `ProtocolViolationException` for
responses. The validator reads the pinned canonical OpenAPI snapshot; it invents
no constraints from descriptions. HTTP error responses retain existing typed
errors and request IDs.

The default remains `false` for compatibility. This mode provides the same
strict-validation capability as Python's always-validated transport and the
opt-in Go and TypeScript modes; default validation policies are intentionally
different. Existing signed-tool, SSE, native-task and Wiki validation remains
active regardless of this option. Binary response bodies and SSE bytes retain
their specialized handling; upload schema validation uses binary placeholders
without reading files. Historical contract-2 replay and completion validation
retains its historical schema rather than applying contract-3 schemas.
`StrictValidationTransport` builds routes from all 146 OpenAPI operations in the
canonical specification, not from `OperationInventory`. It only validates
configured SDK services and custom requests explicitly routed through that
transport; external requests such as signed download URLs are not schema
validated. Schema validation checks wire conformity only and does not confer
authentication or authorization.

In the unreleased source, strict validation also covers the project report
routes. Project log queries accept array filters (`types`, `workspace_ids`,
`api_key_ids`) in repeated (`name[]=a&name[]=b`) or indexed
(`name[0]=a&name[1]=b`) form, but one style per parameter; a mixed style, a gap
in indexes, a bare repeated name or a scalar and plural conflict (`type` with
`types`, `api_key_id` with `api_key_ids`) throws `InvalidArgumentException`. The
`analytics` and `filters` views reject the filters the contract prohibits for
them. A project log entry whose `agent` or `data` is an empty JSON array is
accepted as an empty object, so exact empty-array log metadata from the server
stays compatible.

The canonical OpenAPI document (146 operations) is pinned to backend commit
`f970714c0a358ff94b14f15eabde197c55861e74`. The repository tracks six contract
snapshots pinned to that commit, but the HTTP validator reads only the single
OpenAPI snapshot offline.

Laravel connections expose the corresponding `strict_validation` boolean.
Strict validation is local and performs no network schema fetches, releases,
database migrations, or paid calls.

The standalone CLI reads `ENVAI_STRICT_VALIDATION=true` (also accepts `1`,
`false`, or `0`; default `false`) and preserves it when command-line
connection or state options override the configuration. Invalid values fail
before network access.
