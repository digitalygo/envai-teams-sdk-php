---
status: completed
created_at: 2026-11-11
updated_at: 2026-11-13
files_edited:
  - docs/events.json
  - src/Client/ChatRuntime.php
  - src/Client/Internal/ChatsMessageSender.php
  - src/Models/Chat/ChatMessageRequest.php
  - src/Models/Model/LlmModelEntry.php
  - tests/Client/ChatAttachmentsTest.php
  - tests/Client/ChatRuntimeTest.php
  - tests/Models/ChatTest.php
  - tests/Models/ModelTest.php
  - tests/fixtures/current-server/events.json
  - CHANGELOG.md
  - README.md
  - docs/CURRENT_SERVER_COMPATIBILITY.md
  - docs/EXAMPLES.md
  - docs/README.md
  - docs/STRICT_VALIDATION.md
  - docs/openapi.yaml
  - docs/project-resources.md
  - resources/http/openapi.yaml
  - src/Client/ChatCronsService.php
  - src/Client/ChatsService.php
  - src/Client/EnvaiClient.php
  - src/Client/Internal/ServiceContainer.php
  - src/Client/OperationInventory.php
  - src/Client/ProjectReportsService.php
  - src/Client/ProjectSkillsService.php
  - src/Client/WorkspaceChats.php
  - src/Client/WorkspacesService.php
  - src/Exceptions/ApiException.php
  - src/Exceptions/PaymentRequiredException.php
  - src/Http/Client/ErrorMapper.php
  - src/Http/Client/StrictValidationTransport.php
  - src/Models/Chat/ChatUpdateRequest.php
  - src/Models/ChatCron/ChatCron.php
  - src/Models/ChatCron/ChatCronFilters.php
  - src/Models/ChatCron/ChatCronStatus.php
  - src/Models/ChatCron/ChatCronTargetMode.php
  - src/Models/ChatCron/ChatCronUpdateRequest.php
  - src/Models/Common/CleanupIntentState.php
  - src/Models/Common/CleanupResult.php
  - src/Models/ProjectSkill/ProjectSkill.php
  - src/Models/ProjectSkill/ProjectSkillCreateRequest.php
  - src/Models/ProjectSkill/ProjectSkillUpdateRequest.php
  - src/Models/Reporting/AnalyticsFilters.php
  - src/Models/Reporting/EventLogFilters.php
  - src/Models/Reporting/ProjectActivityAnalytics.php
  - src/Models/Reporting/ProjectEventTypeFilters.php
  - src/Models/Reporting/ProjectLogEntry.php
  - src/Models/Reporting/ProjectLogView.php
  - src/Models/Reporting/ReportFilter.php
  - src/Models/Reporting/ReportQuery.php
  - src/Models/Reporting/ReportingWire.php
  - src/Models/Reporting/TypeCatalogFilters.php
  - src/Models/Reporting/UsageFilters.php
  - src/Models/Reporting/UsageKeyBreakdown.php
  - src/Models/Reporting/UsageMediaTotals.php
  - src/Models/Reporting/UsageMoney.php
  - src/Models/Reporting/UsageReportDay.php
  - src/Streaming/Atp/AdditionalEventDecoder.php
  - src/Streaming/Atp/AtpEventDecoder.php
  - src/Streaming/Events/BillingCreditExhaustedEvent.php
  - src/Streaming/Events/BillingLimitReachedEvent.php
  - src/Streaming/Events/ChatCronWakeupEvent.php
  - src/Streaming/Events/ChatTitleUpdatedEvent.php
  - tests/Client/ChatCronsServiceTest.php
  - tests/Client/CleanupCrudTest.php
  - tests/Client/CleanupFacadeContractTest.php
  - tests/Client/ClientToolRuntimeTest.php
  - tests/Client/InventoryTest.php
  - tests/Client/ManagementFixtures.php
  - tests/Client/NewServiceAccessorsTest.php
  - tests/Client/ProjectReportsTest.php
  - tests/Client/ProjectSkillsServiceTest.php
  - tests/Http/Client/BillingErrorMappingTest.php
  - tests/Http/Client/ProjectReportArrayQueryTest.php
  - tests/Http/Client/RequestExecutorTest.php
  - tests/Models/ChatCronModelTest.php
  - tests/Models/CleanupAndChatUpdateModelTest.php
  - tests/Models/ProjectSkillModelTest.php
  - tests/Models/ReportingTest.php
  - tests/Streaming/Atp/ChatEventStreamTest.php
  - tests/Streaming/Atp/ChatTurnRuntimeTest.php
  - tests/Streaming/Atp/CurrentServerContractTest.php
  - tests/Streaming/Events/AdditionalEventDecoderTest.php
  - tests/fixtures/current-server/provenance.json
rationale:
  - Align project-key integrations with the current backend feature branch without adding human-session administration.
  - Preserve wire compatibility, exact accounting values, conservative retries, and historical ATP v2 bytes.
  - Expose exact per-turn model selection without changing existing positional call conventions.
supporting_docs:
  - https://github.com/digitalygo/envai-teams/tree/7f4085dbfdd3f208b82716119cff1860fbd502bb
  - ../../../AGENTS.md
  - ../../../docs/project-resources.md
  - ../../../docs/CURRENT_SERVER_COMPATIBILITY.md
  - ../../../docs/STRICT_VALIDATION.md
  - ../../../tests/fixtures/current-server/provenance.json
  - https://github.com/digitalygo/1-1-5-envai-aaas/tree/9117967221e433eea4ebabba23b7a2278ad6abc1
---

# Current branch SDK alignment

## Summary of changes

Added 14 project-key operations and four typed events, refreshed the canonical
HTTP snapshots, and documented the unreleased API surface. Work is on
`feature/current-branch-sdk-alignment`, created from a clean, fast-forwarded
`main` at `6957f70c9ca767583ddaa9f3a9a9f4a5be786313` after user approval.

The backend reference is `feature/user-company-projects` at
`9117967221e433eea4ebabba23b7a2278ad6abc1`. No backend files were changed.
Existing backend worktree changes were left intact. No commit, push, tag,
release, deployment, or paid provider call was performed.

## Technical reasoning

The bundled contract already described the missing project-key operations.
Extending the existing typed services and transport was preferable to adding a
second client or exposing a generic human-administration API.

- Added five project-skill operations and four manual cron operations. Partial
  updates preserve omitted versus explicit null values and Markdown whitespace.
  Cron creation remains a runtime tool, not an invented public endpoint.
- Added workspace deletion, chat deletion, and title-only chat updates.
  Deletions expose the HTTP 200 cleanup result rather than pretending the
  response is empty or physical cleanup is complete. Mutations never retry or
  automatically cancel protected cron work after a 409.
- Added paginated project usage and logs, analytics, and the event-type catalog.
  Amounts remain decimal strings, currencies stay separate, and null values
  remain unknown. Iteration rebuilds local project URLs and never follows
  server-provided pagination links.
- Added strict validation for repeated and indexed log-list query parameters.
  The only response-shape accommodation is an exact empty array in log entry
  `agent` or `data`, matching the backend's empty metadata representation.
- Added typed title, cron wakeup, spend-cap, and credit events. Billing notices
  accept a null turn before admission without becoming terminal events or
  weakening ordinary known-event turn requirements.
- Added `PaymentRequiredException` for HTTP 402. The new image-provider
  configuration error remains `ServerException`, preserving existing catches
  and the server error code. No retry or automatic paid-message resubmission
  was introduced.
- Copied both OpenAPI snapshots byte-for-byte from the backend reference and
  refreshed source provenance. All six source snapshots match that commit;
  historical ATP v2 remains unchanged.

The inventory now contains 92 operations, including three existing human-only
API-key methods that fail locally, leaving 89 callable operations. The full
server snapshot contains 146 operations. Human administration, company
financial management, internal service calls, and credential fallback remain
outside the SDK's project-key surface.

Independent inspection caught a page-filter conflict in the initial reporting
implementation. The corrected explicit-page methods override the filter page,
matching the established completions service. Iterators use a filter page when
no start page is supplied and honor an explicit start page while preserving
other filters. Regression tests exercise both strict and ordinary modes.

## Impact assessment

The additions build on tag `v1.3.0`; they are not part of that release. Composer
metadata confirmed that tag as an available version, but this operation does
not publish a new package. Consumers must adopt a reviewed future release or
source revision before using these additions.

Existing service signatures and project-key restrictions are preserved. Native
background tasks remain opt-in. Documentation distinguishes project skills
from workspace files and an access cut from asynchronous physical cleanup.

The implementation reuses `TypedRequester`, `Wire`, `OptionalField`, existing
pagination, error mapping, and transport negotiation. No new dependency,
schema migration, persistence layer, CI configuration, or release automation
was added.

## Validation steps

The orchestrator independently ran the checks below in disposable Podman
runtimes. MySQL 8 and PostgreSQL 16 were isolated, with no public ports and no
connection to application databases.

| Check | Result |
| --- | --- |
| Composer validation | `composer validate --strict` passed on PHP 8.2 and 8.5 |
| Formatting | `vendor/bin/pint --test` passed, 635 PHP files |
| Static analysis | `vendor/bin/phpstan analyse --no-progress` passed on PHP 8.2 and 8.5 |
| PHP 8.5.12 full suite | 2,504 tests, 12,735 assertions, zero errors or failures, 120 optional-framework skips |
| PHP 8.2.35 full suite | 2,504 tests, 12,735 assertions, zero errors or failures, 120 optional-framework skips |
| Laravel 13 integration | Actual Illuminate 13.42 dependencies; Laravel PHPStan passed; 151 tests and 580 assertions passed with `--fail-on-skipped` |
| Executable delta coverage | PCOV Clover: 891 of 891 changed statement lines covered, 100% |
| Dependency audit | Online `composer audit` passed with no advisories |
| Contract provenance | Six snapshots and their hashes verified against the pinned backend Git objects; runtime OpenAPI mirror identical |
| Markdown | Changed guides passed structural lint with MD013 disabled for existing long lines and tables |
| Diff whitespace | `git diff --check` passed |
| Quality judgment | Independent `quality-gate-a`: PASS |
| Security review | Independent `security-review-specialist-a`: PASS, no blocking delta findings |

The PHP 8.2 image initially lacked functional-test extensions. This was an
infrastructure-only failure: the disposable image was corrected to include
`pcntl` and `sockets`, and the complete suite was rerun successfully without
skipping or weakening tests. The optional Laravel dependencies were installed
only in a disposable copy, never added to the SDK's dependency manifest.

The PHP 8.3 and 8.4 matrix entries and Laravel 11 and 12 were not run locally.
No live backend or provider integration was claimed. The two core runs' 120
optional-framework skips were supplemented by the separate actual Laravel 13
run with zero skipped tests.

Local verification evidence is retained in
`/tmp/envai-sdk-leader-delta-coverage.json`,
`/tmp/envai-sdk-leader-php82-final.log`,
`/tmp/envai-sdk-leader-laravel.log`, and
`/tmp/envai-sdk-leader-audit-package.log`. The full PHP 8.5 stdout capture ended
early when the tool timed out, but the process completed and its final JUnit
and coverage artifacts were independently parsed from
`.phpunit.cache/alignment-leader-junit.xml` and
`.phpunit.cache/alignment-leader-coverage.xml`.

The task-created database containers, internal network, imported and built
Podman images, and disposable dependency copies were removed after verification.
No ephemeral server or database remains running. Existing Docker resources and
unrelated backend edits were left untouched.

The reviewed non-trace path-and-content snapshot SHA-256 is
`c65f83b60e8d82e85b2944278a63592e85f24dafcdbdeb38562db9669d2ec89b`.

## Update: 2026-11-13 per-turn model selection

### Summary and reasoning

Starting from SDK `4e55537` on the subsequently renamed
`feature/user-company-projects` branch, refreshed current contracts to backend
`7f4085dbfdd3f208b82716119cff1860fbd502bb`. Pull already included the requested
`alpha` merge; the backend push was up to date and PR 58 pointed to that
revision. Verified GitHub metadata now names the backend
`digitalygo/envai-teams`, without rewriting historical capture provenance.

Appended a model parameter to `ChatMessageRequest` and the three `ChatRuntime`
message helpers, carried by their per-call sender without changing the
`MessageSender` interface. Strings remain exact. Null parameters and
`OptionalField::absent()` omit the field; `OptionalField::of(null)` and malformed
values fail before message submission. Existing strict-model capability gates,
attachment keys and `NeverRetry` remain unchanged. Nullable catalog
`selectable` preserves old hydration conventions and false values without
filtering or granting permission. Documentation states these conventions.

### Impact and verification

No dependency, workflow, version, endpoint or backend source change was made.
All six current snapshots match the backend pin; both OpenAPI copies and event
copies agree, while frozen v2 and historical captures are unchanged.

Independent Composer validation, Pint, PHPStan and Composer audit passed.
The full PHP 8.5.11 suite finished with 2,509 tests, 12,806 assertions, no
failures and 120 pre-existing optional-framework skips. PCOV covered 15/15
changed executable lines. Tests verify all helper paths, per-call omission,
strict policy, attachments and keys, invalid models, single-submit errors and
catalog flags. The initial developer coverage run hit Xdebug's stack limit;
the successful developer and independent runs used PCOV with Xdebug disabled.

Quality judgment and security review returned PASS for non-trace snapshot
`abf519e87e390487d7398207482d9c29c813469ccd0720e204ccaf5011fe6809`.
PHP 8.2/8.3/8.4 and the optional Laravel matrix were not rerun for this update.
The existing local PHP image was reused through Docker because no equivalent
Podman image was available; database services used Podman. SQL checks used
MySQL 8.4 and PostgreSQL 18 rather than CI's PostgreSQL 16. Actionlint was
unavailable, and no workflow changed. No live provider, deployment or release
verification is claimed.

Evidence remains under `/tmp/envai-model-sdk-verify/leader-php*`. All task-created
containers, database volumes, private connection files and the wheel test
environment were removed; the reused image and unrelated services were kept.
