# Strict HTTP validation

Set `new EnvaiConfig(..., strictValidation: true)` to validate canonical HTTP path/query/header parameters and JSON or multipart request bodies before admission or capability preflight. Successful JSON responses are validated as complete wire envelopes before DTO hydration. Constraint violations throw `InvalidArgumentException` for requests and `ProtocolViolationException` for responses. The validator reads the pinned canonical OpenAPI snapshot; it invents no constraints from descriptions. HTTP error responses retain existing typed errors and request IDs.

The default remains `false` for compatibility. This mode provides the same strict-validation capability as Python's always-validated transport and the opt-in Go/TypeScript modes; default validation policies are intentionally different. Existing signed-tool, SSE, native-task and Wiki validation remains active regardless of this option. Binary response bodies and SSE bytes retain their specialized handling; upload schema validation uses binary placeholders without reading files. Historical contract-2 replay/completion validation retains its historical schema rather than applying contract-3 schemas. Signed download URLs and custom endpoints outside the 76-operation canonical inventory are not schema-validated by this option.

Laravel connections expose the corresponding `strict_validation` boolean. Strict validation is local and performs no network schema fetches, releases, database migrations, or paid calls.

The standalone CLI reads `ENVAI_STRICT_VALIDATION=true` (also accepts `1`, `false`,
or `0`; default `false`) and preserves it when command-line connection/state options
override the configuration. Invalid values fail before network access.
