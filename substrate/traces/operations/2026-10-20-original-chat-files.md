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
