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
| Laravel package discovery + application boot | Both providers loaded; package configuration available |
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
Reproduce using [the optional local integration guide](EMAIL-API.md#optional-local-package-development-without-production-path-configuration).
Gmail separately passed 91 tests / 405 assertions on Laravel 12/PHP 8.3 and
Laravel 13/PHP 8.4, plus Composer/Pint/PHPStan; see its package verification.

Live OAuth/consent, approved callback routing for the existing
`https://askmydocsdev.test` site, Google restricted-scope deployment review and
Microsoft organizational/shared-mailbox policies remain operator-run checks.
API sample-download diagnostics are deliberately unavailable; use native
health/folder discovery. No push, tag, PR or remote publication was performed.

## Versioned installation follow-up — 2026-10-16

The host integration was subsequently incorporated into AskMyDocs. Gmail and
Microsoft Mail now have published `v1.0.0` releases, required as `^1.0` in the
production manifest and installed through its lockfile. Their VCS sources are
`padosoft/askmydocs-connector-gmail-api` and
`padosoft/askmydocs-connector-outlook-api`; the Composer package names and connector
keys remain unchanged. Use the [standard installation guide](EMAIL-API.md#standard-versioned-installation)
for Composer installation, package discovery, migrations and OAuth configuration.

The results above remain the 2026-10-15 isolated-worktree verification record.
They do not attest a fresh test run against the 2026-10-16 production dependency
installation or a completed real-provider OAuth/mailbox check.

### Checks against the installed releases

The following checks were rerun on 2026-10-16 after installation in the active
AskMyDocs checkout (`codex/editorial-ui-reorganization`):

| Area | Result |
|---|---|
| Gmail package gates | Composer strict, Pint, PHPStan; 91 tests / 405 assertions |
| Microsoft Mail package gates | Composer strict, Pint, PHPStan; 95 tests / 893 assertions |
| Host connectors, ingestion bridge, sync and triage | 511 tests / 2,332 assertions; no skipped tests |
| Mandatory installed-package integration | Gmail/Microsoft × triage on/off: 4 passing cases, included above |
| Connector frontend | 157 tests / 19 files |
| Typecheck and app/widget build | Passed; existing large-chunk build warning remains |
| Production Composer manifest | Valid; existing exact API-connector beta constraint warning remains |
| Local Laravel discovery and Microsoft migration | Both providers discovered; all three sync tables created |
| Browser catalogue | Gmail and Microsoft Mail available in the authenticated Connections page |

Remote annotated `v1.0.0` tags were published and verified against
`ae1dbfd888ef4ce320e63cdaeb54cfeaddc65293` (Gmail) and
`0131a5f085c701a4b12c6cb789ead1f441923441` (Microsoft Mail). The host lockfile
adds only these two packages and `firebase/php-jwt`; no existing locked package
changed. The pre-existing local Freshdesk 1.1 development copy was restored using
an ignored, temporary Composer path manifest after Composer reconciled it to the
older production lock. The temporary files were removed; no Freshdesk release
or production lock change was included.

Tests use fake provider HTTP, queues and storage. OAuth credentials were absent
from the local environment, so no real mailbox authorization or live acquisition
is certified. Package installation is complete; provider application setup and
account consent remain deployment steps.
