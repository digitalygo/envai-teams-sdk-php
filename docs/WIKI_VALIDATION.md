# Wiki V1 SDK validation

Validated 2026-10-13 against the verified SDK `main` baseline `426517b6af0ce37491b258de026f87310239cf42` and the published Envai Teams Wiki V1 contract at `5bfc6dd3c82af6afc04e7cc1f3323906cfd4f0e2` (based on `b590e6b8758b53346d4e0356c384585373b1b8ea`).

Companion draft PRs: [Envai Teams #45](https://github.com/digitalygo/envai-teams/pull/45) and [Fake Filesystem #9](https://github.com/digitalygo/fake-filesystem-for-agents/pull/9). These are integration/review dependencies, not deployment claims.

## Results

- PHP 8.4.24, PHPUnit 11.5.57, PCOV 1.0.12.
- Final complete SDK suite with isolated Illuminate 12 dependencies and a real PostgreSQL 18.3 database: **2,136 tests, 8,813 assertions, no failures, 25 skipped optional MySQL tests**. No MySQL service was available.
- Executable delta coverage: **576 / 577 statements, 99.83%**. Includes the new migration and completion terminal snapshot DTO. The only uncovered statement is the MySQL-specific `INSERT IGNORE` branch. Per-file details are in `wiki-delta-coverage.json`.
- Focused optional Laravel integration suite: **145 tests, 479 assertions, all passed**, including migration up/down and Laravel PDO-backed durable wiki intake.
- Real PostgreSQL concurrent intake: six independent processes produce one receipt and one application job; one delivery is accepted and five are duplicates.
- Pint aggregate check: passed. PHPStan maximum level: passed. Workflow actionlint: passed. Composer strict validation: passed. Composer security audit: no known vulnerability advisories. Git whitespace check: passed.
- JSON Schema Draft 2020-12 schema validation and all shared wiki fixtures: passed. OpenAPI YAML parses and all component JSON schemas validate. SDK operation inventory matches all **63** combined public operations.

One instrumented complete run hit an existing unrelated wall-clock assertion (`test_retry_after_is_ignored_when_policy_does_not_respect_it`, 0.547 seconds versus its 0.5-second limit). The unchanged test passed on immediate focused rerun; the latest complete PostgreSQL-backed instrumented suite passed. No test was weakened or skipped to obtain the passing result.

## Commands

Core checks from the SDK repository:

```sh
composer install --prefer-dist --no-progress --no-interaction
composer validate --strict
composer audit
vendor/bin/pint --test
vendor/bin/phpstan analyse --no-progress
vendor/bin/phpunit
git diff --check
```

For the extended run, an isolated Composer test harness provided Illuminate 12 config, console, contracts, database, support, HTTP, routing, events and cache packages plus `vlucas/phpdotenv`. It loaded the SDK source/test namespaces without changing the published SDK's dependencies. Configure `ENVAI_TEST_PGSQL_DSN`, `ENVAI_TEST_PGSQL_USER` and `ENVAI_TEST_PGSQL_PASSWORD` for a disposable database. The final full command used that harness bootstrap:

```sh
vendor/bin/phpunit --bootstrap /path/to/isolated-illuminate-bootstrap.php
php -d pcov.enabled=1 -d pcov.directory="$PWD" vendor/bin/phpunit \
  --bootstrap /path/to/isolated-illuminate-bootstrap.php \
  --coverage-clover /path/to/coverage.xml
```

The executable-delta report intersects the Clover executable statement lines with added/modified lines from the verified baseline and includes all executable lines in new source and migration files. Documentation and contract data are non-executable; they are validated with schema, inventory, fixture and byte-hash checks.

## Behaviors covered

- Lazy filesystem-backed multipart upload, inclusive 1,000,000,000-byte size limit, upload/status/download path scoping, escaped identities, 202 status hydration and conversion/indexing distinction.
- Inbox/outbox/sync, immediate withdrawal, category/index reads, one-based inclusive ranges, all/type/revision parameters, public JWKS route, explicit idempotency headers and no automatic mutation retries.
- Typed source/operation/catalog/phase/file models, unknown-field preservation and empty JSON phase-map fidelity.
- Exact raw-byte Ed25519 signing grammar; malformed, missing and duplicated headers; invalid key metadata; wrong workspace; tampering; timestamp/replay bounds; fresh delivery timestamps for old logical events; key rotation and JWKS cache refresh.
- Separate processed/deleted/terminal event classes; event discriminator/state checks; source revision information for out-of-order events; verified deduplication and same-ID/different-payload collision handling.
- Atomic receipt plus durable application work, retry after crash/rollback, verification before database work, nested transaction rejection, missing-table/store failure, connection scoping and real PostgreSQL concurrency.
- Complete/pending/empty provider usage; decimal strings and separate currencies; invalid premature totals; nullable legacy completion/ATP costs; old numeric fixtures and additive typed billing/usage snapshots.
- Existing generic webhook, HTTP/service, ATP, model, inventory, compatibility, sync and Laravel suites included in the aggregate run.

## Limits and release gate

Provider bodies and signing keys in the fixtures are synthetic. The signature vector uses a public RFC 8032 test seed, not credentials. No paid provider call, customer charge, production migration, deployment, remote branch or PR was performed by this validation.

PHP 8.2, 8.3 and 8.5 execution remains for the repository CI matrix; only PHP 8.4 was executed locally. MySQL-specific optional suites and the new inbox MySQL branch remain unverified against a real MySQL service. PostgreSQL and SQLite were executed.

The SDK contract provenance and its regression test are pinned to verified backend commit `5bfc6dd3c82af6afc04e7cc1f3323906cfd4f0e2` on `feat/wiki-platform-v1`. All four shared schema Git blob IDs independently match the SDK snapshots byte-for-byte. Fixture-origin manifests retain their original working-tree capture history; they are synthetic local integration outputs, not production traffic. SDK publication remains separate; no SDK remote branch or PR is claimed by this report.

## Durable completion snapshot integration (2026-10-13)

Added the optional `CompletionTerminalSnapshot` DTO and shared `terminal-snapshot.json` contract without adding endpoints. Missing/null legacy snapshots remain supported. The metadata-only snapshot binds to its containing completion's turn ID, and tests preserve its identity/data while current usage revision and legacy USD projection change. Normal and crash-recovered fixtures validate separately from ATP replay events; response-text fields are rejected by the snapshot schema.

- Focused snapshot, completion, contract provenance and inventory checks: **23 tests, 481 assertions, passed**.
- Snapshot DTO coverage: **18/18 executable statements, 100%**. The final full delta result above includes this addition.
- Interim SDK suite with Illuminate 12 after this addition: **2,125 tests, 8,612 assertions, passed; 51 optional database tests skipped** because this immediate rerun did not start PostgreSQL or MySQL. The earlier PostgreSQL-backed complete run and six-process intake test passed as documented above.
- PHPStan maximum level, Pint, schema/fixture validation, all three OpenAPI mirrors and whitespace checks passed.
- At this integration stage provenance remained explicitly a working-tree snapshot; the verified final pin is recorded in the release gate above.

## Final enriched terminal snapshot review (2026-10-13)

The shared schema, combined/control-plane OpenAPI documents and SDK snapshot now accept optional `chat_usage` with the ATP Usage fields and nullable `chat_cost`. Both are frozen with the original terminal data before journal persistence. Legacy snapshots without chat totals still validate. Tests reject malformed chat totals and response-text extensions, retain null versus numeric costs and round-trip exact metadata. No new public endpoint was added.

The execution-plane tests emitted `actual-completed.json` and `actual-recovered.json` directly from production journal paths against disposable PostgreSQL with synthetic providers. Both exact-byte files and their provenance/hash manifest are mirrored under shared contracts and SDK fixtures. The SDK validates their schemas, hashes, turn binding and DTO round-trips; manually generated pending-cost fixtures remain separate. No real provider invocation or customer data was used. All four shared source schemas are included in the pinned provenance hashes.

Final rerun, including all legacy SDK suites and real PostgreSQL tests:

```sh
export ENVAI_TEST_PGSQL_DSN="pgsql:host=$PGHOST;port=$PGPORT;dbname=$PGDATABASE"
export ENVAI_TEST_PGSQL_USER="$PGUSER" ENVAI_TEST_PGSQL_PASSWORD=""
php -d pcov.enabled=1 -d pcov.directory="$PWD" vendor/bin/phpunit \
  --bootstrap ../sdk-laravel-tests/bootstrap.php \
  --coverage-clover ../sdk-coverage-final.xml
```

This ran inside the workspace's disposable PostgreSQL wrapper with the isolated Illuminate 12 bootstrap. Result: **2,136 tests, 8,813 assertions, no failures; 25 optional MySQL skips**. Total executable delta coverage: **576/577, 99.83%**. PHPStan maximum level, Pint, Composer strict validation/security audit, actionlint, all schema/fixture/mirror checks and whitespace checks passed. PHP 8.2/8.3/8.5 and real MySQL remain unexecuted locally. Backend contract provenance is now pinned to verified commit `5bfc6dd3c82af6afc04e7cc1f3323906cfd4f0e2`.

## Final source diagnostics integration (2026-10-13)

Added optional typed `ConversionState::$errorCode`, `$recoverable` and `$nextRetryAt` after the existing constructor arguments. Missing diagnostics retain the legacy unknown state. The backend's final `recoverable` definition means an eligible automatic recovery is durably scheduled, not merely that operator repair may be possible. Error codes use the backend's sanitized machine-code grammar; fixtures cover scheduled `provider_limit_exceeded`, terminal `audio_track_missing` and exhausted `ffs_document_resource_limit`. Shared schemas, all OpenAPI mirrors and both integration guides match.

Two byte-exact authenticated control-plane GET fixtures are preserved in `fixtures/control-plane-http`, with hashes and synthetic origin. Automated tests validate the actual source response against OpenAPI and its DTO; the actual historical completion retains numeric `cost_usd=0.25`, missing `usage_cost` and null `terminal_snapshot` without inventing usage. The final complete PostgreSQL/Illuminate run above includes these regression tests: **2,136 tests, 8,813 assertions, 25 optional MySQL skips; 576/577 executable delta statements covered (99.83%)**. Static/schema/fixture checks remained green.
