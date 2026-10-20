---
status: completed
created_at: 2026-10-20
files_edited: [src/Client/ChatAttachmentsService.php, src/Client/WorkspaceFilesService.php, src/Http/Client/ContractTransport.php, src/Models/System/Capabilities.php, tests/Client/ChatAttachmentsTest.php, tests/Models/SystemTest.php, tests/Wiki/WikiServicesTest.php, tests/fixtures/chat-attachments/original-ready.json, docs/CHAT_ATTACHMENTS.md, docs/WIKI.md]
rationale: [Support original chat files without automatic conversion, Fail closed before uploads to servers lacking the original-file contract, Preserve default wiki upload behavior]
supporting_docs: [docs/CHAT_ATTACHMENTS.md, docs/WIKI.md]
---

# Original chat files SDK support

## Summary of changes

Native uploads require both chat attachments and original-file delivery capabilities before multipart transmission. Polling recognizes ready originals with conversion status not_required. Wiki source upload adds an optional sixth processing argument: original explicitly stages bytes without conversion; the default convert mode preserves the existing wire payload.

## Technical reasoning

Omitting an explicit original-file contract could still invoke automatic conversion on an older server. Strict boolean capability guards therefore fail before mutation when chat_attachment_originals or wiki_original_sources is unavailable. Idempotency keys, original bytes, filenames, no-blind-retry behavior and legacy read/delete access remain intact. Native readiness does not fabricate a converted derivative.

## Impact assessment

Existing five-argument wiki source uploads remain compatible. Native chat upload now requires the matching Envai server release. There is no dependency, workflow, schema, tag or package-version change in this patch. The user authorized publication as a pull request, not a merge or production deployment. BudgetMind integration follows the server/SDK contract; the development checkout is not presented as an already released package.

## Validation steps

The leader independently ran PHP 8.2 Composer validation, Pint, PHPStan and Composer audit, and PHP 8.5 PHPUnit with and without coverage in disposable Podman workspaces. All commands exited 0. PHPUnit executed 2244 cases with 9045 assertions and 169 existing optional Illuminate/database-DSN skips; no changed-behavior test skipped. The changed executable source coverage is 40/43 statements (93.02%). PHP 8.3/8.4 and real MySQL/PostgreSQL SDK adapters remain CI-matrix checks, not locally claimed results. Release tooling is unchanged.

```bash
bash /tmp/envai-sdk-verify-native-chat-files/run-checks.sh all
podman exec sdk-verify-native-chat-files-85 sh -lc 'cd /work && XDEBUG_MODE=coverage php -d pcov.directory=/work -d pcov.enabled=1 vendor/bin/phpunit --coverage-clover=/tmp/sdk-native-leader.xml'
git diff --check
```

Source manifests were compared against the actual worktree and both verification copies with no drift. Frozen ten-file package: 19ef2713b077b330ed94c0b0f0aa81c427dc02182bb9ee13b67c46c31d336914. Independent quality PASS and focused security PASS cover this exact package. No provider, OCR, production API or real user-file request was made.

The repository has no pull request template or directives/expectations directories. AGENTS.md and the current CI workflow were read. Review capability failures before multipart, ready/not_required polling, original source field serialization and unchanged default-mode behavior. Rollback through a reviewed compatibility change, never by silently allowing OCR fallback. Temporary test resources are owned by the parent task and are removed after combined verification.

## Update 2026-10-20: canonical capability-list interoperability

Local activation exposed a contract mismatch missed by the original boolean-map fixtures. The real Envai v3 endpoint and the published Go CapabilitiesResponse both advertise enabled capabilities as a string list. All three required original-file names were present, but SDK v1.2.0 and the consumer's map-only gate rejected that valid response. The earlier diagnostic of absent server capabilities was therefore incorrect; the consumer interpretation was wrong.

The SDK now accepts an exact required name in a homogeneous positive string list or an exact true value in a strict boolean map. Missing new names, old lists advertising only chat attachments, false values, mixed lists and non-boolean maps still fail before multipart transmission. Protocol negotiation, authorization, original bytes, idempotency, no-blind-retry behavior and the no-OCR fallback guarantee are unchanged. No server or schema change is needed.

A canonical fixture and provenance record are tied to the actual Envai Go source. New interoperability tests exercise both native attachment and original wiki uploads with GET-before-POST ordering, exact bytes/checksums, original mode and unchanged keys. The old test that incorrectly rejected a fully advertised list was corrected explicitly; negative missing-name coverage was preserved and expanded.

The leader independently ran PHP 8.2 Composer validation/Pint/PHPStan/audit and the full PHP 8.5 suite plus Clover coverage. All exited 0: 2250 cases, 9119 assertions, 169 existing optional Illuminate/database-DSN skips; ContractTransport covers 179/180 executable statements. Frozen eight-file package 3994e37dcb0aea29ddcf6042706453f4a9d84dd4bdbd86ffba90383179b406a0 received quality PASS and focused security PASS. A subsequent guide-only clarification included both required names in the map example and clarified that unrelated names are ignored, not rejected; executable files are unchanged.

No real file or paid inference was used. BudgetMind remains fail-closed on installed SDK v1.2.0 until this correction is released and its own admission gate is aligned. Publication is a corrective SDK pull request under the previously authorized feature workflow; merge/tag/deployment is not claimed here.
