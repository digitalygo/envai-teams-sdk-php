# ATP contract examples

Illustrative examples for the Agent Team Protocol (ATP) shared contracts in
this package. The authoritative schemas are `events.json` (event envelope and
payloads) and `openapi.yaml` (chat endpoints). The sequences mirror
`docs/agent-team-protocol.md` and the payloads follow the schema sections. All
identifiers use the repository `xxx_<ulid>` convention.

## Envelope

Every event uses one envelope. `turn_id` is the orchestrator completion id
`cmpl_<ulid>` of the turn the event belongs to and is null only for `ping`.
`agent` is null for `turn.*` events and for `approval.response`; it is a
non-null agent instance for `agent.*` and `approval.request` and
`approval.expire`.

```json
{
  "v": 2,
  "id": "evt_01j5abcdefghjkmnpqrstvwxyz",
  "seq": 1,
  "ts": "2026-08-17T14:00:00Z",
  "chat_id": "cht_01j5abcdefghjkmnpqrstvwxyz",
  "turn_id": "cmpl_01j5abcdefghjkmnpqrstuvwxyz",
  "agent": null,
  "type": "turn.started",
  "data": {
    "turn_id": "cmpl_01j5abcdefghjkmnpqrstuvwxyz",
    "input": "Analizza il traffico dell'ultima settimana"
  }
}
```

## Event sequences

Each sequence shows the Server-Sent Events wire format: one `data:` line per
event, as served by `GET /api/v1/chats/{chat_id}/events`. Positive sequence
numbers are monotonic and continuous across turns while the chat runtime stays
resident. `?after=<seq>` replays only the retained in-memory tail; eviction or
restart currently resets the sequence and discards that replay window.

### Simple turn

```text
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxy1","seq":1,"ts":"2026-08-17T14:00:00Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyz","agent":null,"type":"turn.started","data":{"turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyz","input":"Analizza il traffico dell'ultima settimana"}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxy2","seq":2,"ts":"2026-08-17T14:00:01Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyz","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxyz","name":"orchestrator","role":"orchestrator","parent_id":null},"type":"agent.started","data":{"name":"orchestrator","role":"orchestrator","model":"anthropic/claude-sonnet-4-5","parent_id":null}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxy3","seq":3,"ts":"2026-08-17T14:00:01Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyz","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxyz","name":"orchestrator","role":"orchestrator","parent_id":null},"type":"agent.plan","data":{"plan":"Delego la raccolta dati al researcher e poi sintetizzo."}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxy4","seq":4,"ts":"2026-08-17T14:00:02Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyz","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxyz","name":"orchestrator","role":"orchestrator","parent_id":null},"type":"agent.text.delta","data":{"delta":"Ecco l'analisi del traffico:"}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxy5","seq":5,"ts":"2026-08-17T14:00:03Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyz","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxyz","name":"orchestrator","role":"orchestrator","parent_id":null},"type":"agent.text.done","data":{"text":"Ecco l'analisi del traffico: 12k visitatori, +8% rispetto alla scorsa settimana."}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxy6","seq":6,"ts":"2026-08-17T14:00:03Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyz","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxyz","name":"orchestrator","role":"orchestrator","parent_id":null},"type":"agent.done","data":{"status":"completed","summary":"Turno completato","usage":{"input_tokens":120,"output_tokens":450,"cost_usd":0.42,"duration_ms":8123,"sprite_ms":0},"duration_ms":8123}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxy7","seq":7,"ts":"2026-08-17T14:00:03Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyz","agent":null,"type":"turn.completed","data":{"turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyz","usage":{"input_tokens":120,"output_tokens":450,"cost_usd":0.42,"duration_ms":8123,"sprite_ms":0},"cost":0.42,"chat_usage":{"input_tokens":120,"output_tokens":450,"cost_usd":0.42,"duration_ms":8123,"sprite_ms":0},"chat_cost":0.42,"finish_reason":"completed"}}
```

### Second turn, continuous sequence

The sequence never resets: the next message continues from `seq` 8, and the
terminal event reports both the turn totals and the accumulated chat totals.

```text
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxy8","seq":8,"ts":"2026-08-17T14:05:00Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyA","agent":null,"type":"turn.started","data":{"turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyA","input":"E confronta con il mese scorso"}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxy9","seq":9,"ts":"2026-08-17T14:05:01Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyA","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxyB","name":"orchestrator","role":"orchestrator","parent_id":null},"type":"agent.started","data":{"name":"orchestrator","role":"orchestrator","model":"anthropic/claude-sonnet-4-5","parent_id":null}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyA","seq":10,"ts":"2026-08-17T14:05:03Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyA","agent":null,"type":"turn.completed","data":{"turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyA","usage":{"input_tokens":200,"output_tokens":300,"cost_usd":0.5,"duration_ms":12345,"sprite_ms":0},"cost":0.5,"chat_usage":{"input_tokens":320,"output_tokens":750,"cost_usd":0.92,"duration_ms":20468,"sprite_ms":0},"chat_cost":0.92,"finish_reason":"completed"}}
```

### Delegation

```text
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyB","seq":11,"ts":"2026-08-17T14:06:00Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyC","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxyd","name":"orchestrator","role":"orchestrator","parent_id":null},"type":"agent.delegated","data":{"child_instance_id":"ag_01j5abcdefghjkmnpqrstvwxye","child_name":"researcher","mode":"foreground","title":"Raccogli traffico","task":"Raccogli i dati di traffico degli ultimi 7 giorni"}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyC","seq":12,"ts":"2026-08-17T14:06:00Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyC","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxye","name":"researcher","role":"member","parent_id":"ag_01j5abcdefghjkmnpqrstvwxyd"},"type":"agent.started","data":{"name":"researcher","role":"member","model":"anthropic/claude-haiku-4-5","parent_id":"ag_01j5abcdefghjkmnpqrstvwxyd"}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyD","seq":13,"ts":"2026-08-17T14:06:01Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyC","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxye","name":"researcher","role":"member","parent_id":"ag_01j5abcdefghjkmnpqrstvwxyd"},"type":"agent.reasoning.delta","data":{"delta":"Cerco le fonti sul traffico..."}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyE","seq":14,"ts":"2026-08-17T14:06:02Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyC","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxye","name":"researcher","role":"member","parent_id":"ag_01j5abcdefghjkmnpqrstvwxyd"},"type":"agent.tool.start","data":{"call_id":"call_01j5abcdefghjkmnpqrstvwxyz","tool_name":"read_wiki","kind":"knowledge","args":{"page":"traffic"}}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyF","seq":15,"ts":"2026-08-17T14:06:03Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyC","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxye","name":"researcher","role":"member","parent_id":"ag_01j5abcdefghjkmnpqrstvwxyd"},"type":"agent.tool.complete","data":{"call_id":"call_01j5abcdefghjkmnpqrstvwxyz","result":"Dati estratti dalla wiki","is_error":false,"usage":{"input_tokens":50,"output_tokens":80,"cost_usd":0.05,"duration_ms":1000,"sprite_ms":0}}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyG","seq":16,"ts":"2026-08-17T14:06:04Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyC","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxye","name":"researcher","role":"member","parent_id":"ag_01j5abcdefghjkmnpqrstvwxyd"},"type":"agent.text.delta","data":{"delta":"12k visitatori, +8%."}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyH","seq":17,"ts":"2026-08-17T14:06:05Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyC","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxye","name":"researcher","role":"member","parent_id":"ag_01j5abcdefghjkmnpqrstvwxyd"},"type":"agent.done","data":{"status":"completed","summary":"Dati raccolti","usage":{"input_tokens":50,"output_tokens":80,"cost_usd":0.05,"duration_ms":1000,"sprite_ms":0},"duration_ms":1000}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyI","seq":18,"ts":"2026-08-17T14:06:06Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyC","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxyd","name":"orchestrator","role":"orchestrator","parent_id":null},"type":"agent.text.delta","data":{"delta":"Sintesi: 12k visitatori, +8% rispetto alla scorsa settimana."}}
```

### Escalation to approval

This is a normative ATP sequence. Current alpha does not advertise the
`approval` feature and `POST /api/v1/chats/{chat_id}/approval` returns `409`.

```text
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyJ","seq":19,"ts":"2026-08-17T14:07:00Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyC","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxye","name":"researcher","role":"member","parent_id":"ag_01j5abcdefghjkmnpqrstvwxyd"},"type":"agent.escalate","data":{"escalation_id":"escal_01j5abcdefghjkmnpqrstvwxyz","agent_id":"ag_01j5abcdefghjkmnpqrstvwxye","reason":"La scrittura su wiki richiede approvazione","tool_name":"write_wiki","args":{"page":"traffic"}}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyK","seq":20,"ts":"2026-08-17T14:07:00Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyC","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxyd","name":"orchestrator","role":"orchestrator","parent_id":null},"type":"approval.request","data":{"approval_id":"appr_01j5abcdefghjkmnpqrstvwxyz","origin_agent_id":"ag_01j5abcdefghjkmnpqrstvwxye","tool_name":"write_wiki","args":{"page":"traffic"},"reason":"La scrittura su wiki richiede approvazione","deadline":"2026-08-17T14:10:00Z"}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyL","seq":21,"ts":"2026-08-17T14:07:30Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyC","agent":null,"type":"approval.response","data":{"approval_id":"appr_01j5abcdefghjkmnpqrstvwxyz","approve":true,"reason":"Ok"}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyM","seq":22,"ts":"2026-08-17T14:07:30Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyC","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxyd","name":"orchestrator","role":"orchestrator","parent_id":null},"type":"agent.escalation.result","data":{"escalation_id":"escal_01j5abcdefghjkmnpqrstvwxyz","approve":true,"reason":"Ok"}}
```

### Client tool

```text
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyN","seq":23,"ts":"2026-08-17T14:08:00Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyC","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxye","name":"researcher","role":"member","parent_id":"ag_01j5abcdefghjkmnpqrstvwxyd"},"type":"agent.tool.request","data":{"call_id":"tc_01j5abcdefghjkmnpqrstuvwxyz","tool_name":"lookup_order","kind":"client","args":{"order_id":"ord_123"},"signature":{"alg":"EdDSA","kid":"sig_01j5abcdefghjkmnpqrstvwxyz","sig":"4Z7vK1QpR8wXcB2mN6sT9yDfG0hJqLzMxVbNcAsDfGhJkLzXcVbNmQsWtY"}}}
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyO","seq":24,"ts":"2026-08-17T14:08:05Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyC","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxye","name":"researcher","role":"member","parent_id":"ag_01j5abcdefghjkmnpqrstvwxyd"},"type":"agent.tool.result","data":{"call_id":"tc_01j5abcdefghjkmnpqrstuvwxyz","output":"{\"order_id\":\"ord_123\",\"total_cents\":4900}","is_error":false}}
```

The client verifies the EdDSA signature (same Ed25519 key family that signs the api key jwt, `kid`
matching `/.well-known/jwks.json`) over the canonical payload
`call_id + "\n" + tool_name + "\n" + canonical_json(args)` per RFC 8785 before executing, then
posts the output to `POST /api/v1/chats/{chat_id}/tool-output`. If no output arrives within the
tool timeout (default 120 seconds, max 600), the gateway auto-emits the error result instead:

```text
data: {"v":2,"id":"evt_01j5abcdefghjkmnpqrstvwxyP","seq":25,"ts":"2026-08-17T14:10:05Z","chat_id":"cht_01j5abcdefghjkmnpqrstvwxyz","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyC","agent":{"instance_id":"ag_01j5abcdefghjkmnpqrstvwxye","name":"researcher","role":"member","parent_id":"ag_01j5abcdefghjkmnpqrstvwxyd"},"type":"agent.tool.result","data":{"call_id":"tc_01j5abcdefghjkmnpqrstuvwxy1","output":"{\"code\":\"tool_timeout\",\"message\":\"Client tool output was not received before the deadline\"}","is_error":true}}
```

No `agent.tool.start`/`delta`/`complete` for client tools: the request/result pair is the whole
lifecycle because execution happens outside the platform.

## HTTP requests and responses

The gateway serves under `/api/v1`. The bearer token is the long lived api key
jwt `envai_sk_<ulid><jwt>`.

### Send a message

```bash
curl -X POST http://localhost:8081/api/v1/chats/cht_01j5abcdefghjkmnpqrstvwxyz/messages \
  -H "Authorization: Bearer <jwt>" \
  -H "Content-Type: application/json" \
  -d '{"message":"Analizza il traffico dell''ultima settimana"}'
```

```json
{ "status": "accepted", "turn_id": "cmpl_01j5abcdefghjkmnpqrstuvwxyz" }
```

A second message while the first turn is still active answers 409 with
`turn_in_progress`.

### Stream events

```bash
curl -N "http://localhost:8081/api/v1/chats/cht_01j5abcdefghjkmnpqrstvwxyz/events?after=0" \
  -H "Authorization: Bearer <jwt>" \
  -H "Accept: text/event-stream"
```

Replay retained events with `?after=<seq>`. Positive sequence numbers remain
continuous across turns only while the chat runtime is resident. The default
in-memory window is 10,000 events; eviction or restart discards it and resets
the sequence without an epoch or cursor-expired signal.

### Cancel the active turn

```bash
curl -X POST http://localhost:8081/api/v1/chats/cht_01j5abcdefghjkmnpqrstvwxyz/cancel \
  -H "Authorization: Bearer <jwt>"
```

```json
{ "turn_id": "cmpl_01j5abcdefghjkmnpqrstuvwxyz" }
```

When no turn is in progress the request answers 409 with
`no_turn_in_progress`.

### Steer

```bash
curl -X POST http://localhost:8081/api/v1/chats/cht_01j5abcdefghjkmnpqrstvwxyz/steer \
  -H "Authorization: Bearer <jwt>" \
  -H "Content-Type: application/json" \
  -d '{"text":"Preferisci il provider più economico per questo turno","target_agent_id":"ag_01j5abcdefghjkmnpqrstvwxyz"}'
```

```json
{ "turn_id": "cmpl_01j5abcdefghjkmnpqrstuvwxyz" }
```

Current alpha records the request and emits `turn.steered`; it does not inject
the text into the running LLM loop.

### Fork

Create the destination chat through the control plane first, then fork by
referencing it.

```bash
curl -X POST http://localhost:8081/api/v1/chats/cht_01j5abcdefghjkmnpqrstvwxyz/fork \
  -H "Authorization: Bearer <jwt>" \
  -H "Content-Type: application/json" \
  -d '{"destination_chat_id":"cht_01j5abcdefghjkmnpqrstuvwxyZ"}'
```

```json
{
  "source_chat_id": "cht_01j5abcdefghjkmnpqrstvwxyz",
  "destination_chat_id": "cht_01j5abcdefghjkmnpqrstuvwxyZ"
}
```

### Restore

Rebuild the orchestrator from client held context: the compacted context
first, followed by orchestrator assistant messages. Restore overwrites the
persisted context of the chat and does not open a turn, so `turn_id` answers
null.

```bash
curl -X POST http://localhost:8081/api/v1/chats/cht_01j5abcdefghjkmnpqrstvwxyz/restore \
  -H "Authorization: Bearer <jwt>" \
  -H "Content-Type: application/json" \
  -d '{"orchestrator_context":[{"kind":"compacted","content":"Compattazione del contesto fino al turno cmpl_01j5abcdefghjkmnpqrstuvwxyz."},{"kind":"assistant","turn_id":"cmpl_01j5abcdefghjkmnpqrstuvwxyz","content":"Ecco l''analisi del traffico: 12k visitatori, +8% rispetto alla scorsa settimana."}]}'
```

```json
{ "status": "restored", "turn_id": null }
```

### Resolve a client tool output

```bash
curl -X POST http://localhost:8081/api/v1/chats/cht_01j5abcdefghjkmnpqrstvwxyz/tool-output \
  -H "Authorization: Bearer <jwt>" \
  -H "Content-Type: application/json" \
  -d '{"call_id":"tc_01j5abcdefghjkmnpqrstuvwxyz","output":"{\"order_id\":\"ord_123\",\"total_cents\":4900}","is_error":false}'
```

Answers `204 No Content`. During the active turn, resolution is at most once by
`call_id`, not transparently retry-safe: a duplicate answers `409`
`tool_already_resolved` and a late output answers `409` `tool_timed_out`. After
the terminal event the broker and tombstones are discarded, so the same id
answers `404`; unknown and cross-chat ids also answer `404` without leaking
existence.

### Register a client tool (control plane)

Client tools are user-scoped: a tool is registered once per user and shared
across all the user's workspaces.

```bash
curl -X POST http://localhost:8111/api/v1/tools \
  -H "Authorization: Bearer <jwt>" \
  -H "Content-Type: application/json" \
  -d '{"name":"lookup_order","kind":"client","description":"Look up an order by id","parameters":{"type":"object","properties":{"order_id":{"type":"string"}},"required":["order_id"]},"timeout_seconds":120}'
```

```json
{
  "data": {
    "id": "twl_01j5abcdefghjkmnpqrstvwxyz",
    "name": "lookup_order",
    "kind": "client",
    "description": "Look up an order by id",
    "parameters": {
      "type": "object",
      "properties": { "order_id": { "type": "string" } },
      "required": ["order_id"]
    },
    "timeout_seconds": 120,
    "created_at": "2026-08-17T14:00:00Z",
    "updated_at": "2026-08-17T14:00:00Z"
  }
}
```

List with `GET /api/v1/tools`, read and update a single tool with
`GET`/`PUT /api/v1/tools/{name}` (only `description`, `parameters` and
`timeout_seconds` are updatable) and remove it with `DELETE` (answers `204`).
The tool is visible to an agent only when its name is in
`agent_definitions.external_tools_enabled` and it is registered by the same
user on the platform; names are unique per user.

### Capabilities

```bash
curl http://localhost:8081/api/v1/capabilities \
  -H "Authorization: Bearer <jwt>"
```

```json
{
  "protocol_version": 2,
  "families": ["turn", "agent"],
  "event_types": [
    "ping",
    "turn.started",
    "turn.completed",
    "turn.cancelled",
    "turn.failed",
    "turn.steered",
    "agent.started",
    "agent.plan",
    "agent.text.delta",
    "agent.text.done",
    "agent.reasoning.delta",
    "agent.reasoning.done",
    "agent.delegated",
    "agent.tool.start",
    "agent.tool.delta",
    "agent.tool.complete",
    "agent.tool.request",
    "agent.tool.result",
    "agent.done",
    "agent.failed",
    "approval.request",
    "approval.response",
    "approval.expire"
  ],
  "tool_kinds": ["server", "client", "knowledge", "code"],
  "features": ["replay", "fork", "restore", "steer", "ping", "background_delegation", "client_tools"]
}
```

## Event type index

| Family | Type | Key data fields |
| --- | --- | --- |
| Turn | `turn.started` | `turn_id`, `input` |
| Turn | `turn.completed` | `turn_id`, `usage`, `cost`, `chat_usage`, `chat_cost`, `finish_reason` |
| Turn | `turn.cancelled` | `turn_id`, `reason`, `usage`, `cost`, `chat_usage`, `chat_cost` |
| Turn | `turn.failed` | `turn_id`, `code`, `message`, `usage`, `cost`, `chat_usage`, `chat_cost` |
| Turn | `turn.steered` | `turn_id`, `text`, `target_agent_id?` |
| System | `ping` | none |
| Agent | `agent.started` | `name`, `role`, `model`, `parent_id` |
| Agent | `agent.plan` | `plan` |
| Agent | `agent.text.delta` | `delta` |
| Agent | `agent.text.done` | `text` |
| Agent | `agent.reasoning.delta` | `delta` |
| Agent | `agent.reasoning.done` | `text` |
| Agent | `agent.delegated` | `child_instance_id`, `child_name`, `mode`, `title`, `task` |
| Agent | `agent.tool.start` | `call_id`, `tool_name`, `kind`, `args` |
| Agent | `agent.tool.delta` | `call_id`, `delta` |
| Agent | `agent.tool.complete` | `call_id`, `result`, `is_error`, `usage` |
| Agent | `agent.tool.request` | `call_id`, `tool_name`, `kind`, `args`, `signature` |
| Agent | `agent.tool.result` | `call_id`, `output`, `is_error` |
| Agent | `agent.escalate` | `escalation_id`, `agent_id`, `reason`, `tool_name`, `args` |
| Agent | `agent.escalation.result` | `escalation_id`, `approve`, `reason` |
| Agent | `agent.artifact` | `artifact_id`, `name`, `media_type`, `size` |
| Agent | `agent.context.compacting` | none |
| Agent | `agent.context.compacted` | `new_context` |
| Agent | `agent.done` | `status`, `summary`, `usage`, `duration_ms` |
| Agent | `agent.failed` | `code`, `message` |
| Approval | `approval.request` | `approval_id`, `origin_agent_id`, `tool_name`, `args`, `reason`, `deadline` |
| Approval | `approval.response` | `approval_id`, `approve`, `reason` |
| Approval | `approval.expire` | `approval_id` |
