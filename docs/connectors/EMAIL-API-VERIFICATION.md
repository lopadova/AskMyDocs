# Email API host verification

Date: 2026-10-15. Base `origin/develop`: `f2a9e133a`.
Worktree: `/Users/marco/.codex/worktrees/gmail-email-api/AskMyDocsDev`.
Branch: `feature/email-api-connectors`.

Functional commits: `1a7df26c2` (v1 adapter/receipts) and `1153f1b4f`
(campaigns/UI/diagnostics). The shared develop checkout and its existing changes
were not edited. The Flow persistence delta is limited to retaining the API
provider key for final v1 revision/provenance/ACL checks; other providers keep
existing behavior. No installed vendor source was patched.

## Passing checks

| Area | Result |
|---|---|
| API/IMAP triage, campaigns, assistant, probe, engine, tenant scope | 118 tests / 732 assertions |
| Legacy bridge regression | 47 tests / 107 assertions |
| Native local Gmail/Microsoft acquisition + host bridge on/off; registry | 7 tests / 100 assertions |
| Shared connector UI component suite | 37 tests |
| Synthetic browser UI: provider × theme × viewport | 16/16 combinations |
| `npm run typecheck` | Passed |
| PHP lint + `git diff --check` | Passed |
| Pint new adapter classes and new tests | Passed; existing host whole-file formatting retained |
| Composer production manifest validation | Valid; existing exact beta constraint warning unchanged |

The common host regression command was:

```sh
TEST_TOKEN=email_api_core_final vendor/bin/phpunit \
  tests/Feature/Connectors/EmailApiTriageTest.php \
  tests/Feature/Connectors/EmailApiTriageCampaignTest.php \
  tests/Feature/Connectors/EmailTriageTest.php \
  tests/Feature/Connectors/EmailTriageAssistantTest.php \
  tests/Feature/Connectors/ConnectorEmailProbeTest.php \
  tests/Unit/Decisions/EmailTriageEngineTest.php \
  tests/Architecture/TenantReadScopeTest.php --no-progress
TEST_TOKEN=email_bridge_legacy vendor/bin/phpunit \
  tests/Feature/Connectors/HostIngestionBridgeTest.php \
  --filter '/^(?!.*test_generated_imap_fixture_propagates_sharded_index_metadata_to_the_job).*$/' \
  --no-progress
TEST_TOKEN=email_api_final vendor/bin/phpunit \
  tests/Feature/Connectors/EmailApiPackageIntegrationTest.php \
  tests/Architecture/ConnectorRegistryTest.php --no-progress
```

Only the unrelated large gold sharded-fixture generator was excluded from the
bridge subset. Fixture tests use SQLite memory databases, fake storage/queues
and HTTP with `preventStrayRequests`. No analysis worker, paid AI or real mailbox
was executed. Native smoke reaches the real bridge and stages the common triage
state; actual conversion/index/ACL/version/cost reuse is covered by the host
v1 contract fixtures for both providers and the legacy IMAP suite.

The UI command was:

```sh
npm test -- --maxWorkers=1 --testTimeout=15000 \
  frontend/src/features/admin/connectors/AccountEditModal.test.tsx \
  frontend/src/features/admin/connectors/ConnectionActionsMenu.test.tsx \
  frontend/src/features/admin/connectors/EmailTriagePanel.test.tsx
npm run typecheck
```

Synthetic Chromium component rendering covered Gmail and Microsoft Mail in
light and dark themes at 320×568, 390×844, 667×375 and 1280×900 pixels: 16/16
combinations passed. Keyboard checks covered tab order, Space/Enter activation,
checkbox and numeric input editing, Save payload, Cancel and close. Labels,
focus rings, tabs and footer actions fit without horizontal overflow or
JavaScript errors. This is browser component QA; no claim is made about a
signed-in Herd OAuth session or native mobile device.

## Local dependency boundary

The ignored `composer.email-local.json`/lock install mirrored local repositories
in this isolated worktree. Production `composer.json` and `composer.lock` are
unchanged. The final smoke uses Gmail `585452d` and Microsoft Mail `e6be1f3`,
with runtime source/config/migration/icon bytes compared to their local repos.
Reproduce using [the local integration guide](EMAIL-API.md#local-package-integration-without-production-path-configuration).
Gmail separately passed 91 tests / 405 assertions on Laravel 12/PHP 8.3 and
Laravel 13/PHP 8.4, plus Composer/Pint/PHPStan; see its package verification.

Live OAuth/consent, approved callback routing for the existing
`https://askmydocsdev.test` site, Google restricted-scope deployment review and
Microsoft organizational/shared-mailbox policies remain operator-run checks.
API sample-download diagnostics are deliberately unavailable; use native
health/folder discovery. No push, tag, PR or remote publication was performed.
