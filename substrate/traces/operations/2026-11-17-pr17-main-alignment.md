# PR 17 alignment with main

## Scope and inputs

Merge current SDK main `e14f2052fbdff7bd5907169b45dcd3d60f8364d1`
(release tag v1.4.0, merged PR 18) into PR 17 head
`a22ac691ff6815ff38aed900898515be093a5a4a` on
`feature/user-company-projects`. No PR approval, PR merge, release, tag,
deployment, backend change or dependency declaration change is part of this work.

Read the repository AGENTS.md and CI workflow, plus digitalygo/dotfiles
AGENTS.md and shared backend-dev and api-design skills. No repository-local
.agents/skills, directives or expectations were present. Existing implementation
and tests resolve the work; no new API abstraction is required.

## Reconciliation

- Preserve all 14 PR 17 operations: 92 inventory entries, 89 callable. Keep
  chat crons, project skills, project reports, cleanup deletion and chat rename,
  typed billing/title/cron events, exact money and non-retryable mutations.
- Keep PR 18's published nullable-string model API and exact serialization.
  The unreleased OptionalField model variant and eager constructor rejection
  from PR 17 are superseded by that API. Ordinary messages do not require
  native task or strict_model capability gates. Other native gates remain.
- Retain tests from both branches for per-turn isolation, ordered attachments,
  idempotency, no automatic retries and live-stream confirmation. Adapt the
  model DTO test to published pass-through behavior; strict transport retains
  all 25 Unicode boundary whitespace cases, empty/null/non-string rejection,
  and internal newline/non-ASCII acceptance.
- Preserve PR 18's artifact metadata, managed Wiki contracts, image notification
  contract and static/runtime tests. The notification receiver is not an SDK
  inventory operation.
- Pin all six source contracts to backend alpha commit
  `83979ec1ce939f0313dffefcc586cac7d3426301`. All six computed Git blob IDs
  match the backend directory listing. OpenAPI is byte-exactly the PR 18
  snapshot plus the Unicode-boundary fix already present in PR 17. Preserve
  immutable historical ATP v2 and historical HTTP snapshots.
- Refresh documentation and fixture mirrors. Describe v1.4.0 as the existing
  baseline and the 14 operations as unreleased additions.

## Local verification

PHP 8.4.27 installed in an isolated workspace runtime; dependency manifests
remain unchanged and the generated Composer lock is ignored by this library.

- `vendor/bin/phpunit`: 2,525 tests, 12,759 assertions, no failures/errors,
  180 skips for optional framework and unconfigured database integrations.
- `vendor/bin/pint --test`: passed.
- `vendor/bin/phpstan analyse`: passed.
- `composer validate --strict`: passed.
- `composer audit`: no security vulnerability advisories.
- `actionlint` 1.7.12: passed; workflows were not changed.
- `git diff --check`: passed.
- Isolated Laravel 13.42 dependency installation: PHPStan with
  `phpstan.laravel.neon` passed. The exact CI PHPUnit command completed 151
  tests and 498 assertions with 2 MySQL skips, so `--fail-on-skipped` exited 1.
  The remaining 149 tests passed. Disposable MySQL was not configured locally.

The first run exposed missing sockets in the local PHP runtime; enabling the
extension resolved all HTTP integration errors without modifying SDK code.
PHPStan initially exceeded the runtime's 128 MiB limit and passed at 512 MiB.

PHP 8.2/8.3/8.5, Laravel 11/12 and MySQL/PostgreSQL matrix verification are
reserved for CI on the published merge commit. No live provider or paid API
calls were made. CI conclusions must be reported from the final commit's run,
not inferred from this local record or older green runs.
