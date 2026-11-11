# Shared PostgreSQL tool state

`PostgresClientToolCallStore` provides durable atomic claims across independent
workers. It implements both `ClientToolCallStore` and `ToolReplayBarrierStore`.
Supply a PostgreSQL PDO connection configured with `PDO::ERRMODE_EXCEPTION` and
a stable, non-secret account/deployment scope:

```php
use Digitalygo\Envai\Tools\State\PostgresClientToolCallStore;

$store = new PostgresClientToolCallStore($pdo, 'account-production');
```

Apply the statements returned by
`PostgresClientToolCallStore::schemaStatements()` through your reviewed migration
process before constructing a runner. The adapter never creates tables or
provisions credentials. An optional validated table prefix must match between
the migration and constructor.

Each operation owns a short transaction on an otherwise idle connection and
requires PostgreSQL synchronous commit. A caller-owned transaction is rejected:
claims must commit before the handler starts. Do not share one PDO connection
between overlapping operations. Independent workers may share the migrated
tables and scope using their own connections.

Claims are scoped by account/deployment, chat and call. Existing and ambiguous
claims never expire or become runnable automatically. Invalid persisted state or
a reused call with a different tool name fails closed. Resolved status cannot be
downgraded. Task, turn and call replay barriers survive runner recreation and are
isolated by scope and chat. Rotating an API key must not change the scope.

These guarantees prevent automatic re-execution of claimed work; they do not
make external side effects transactional with the database. Reconcile downstream
effects before changing ambiguous state. A failed or uncertain database commit
propagates an error and is never automatically retried by this adapter.

The PostgreSQL tests use `ENVAI_TEST_PGSQL_DSN`, `ENVAI_TEST_PGSQL_USER` and
`ENVAI_TEST_PGSQL_PASSWORD` against a disposable test database. They exercise six
independent competing processes, scope isolation, persistent barriers, corrupted
rows, terminal-state monotonicity and rejection of caller transactions.
